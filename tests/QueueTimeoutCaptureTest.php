<?php

declare(strict_types=1);

namespace Tiden\Laravel\Tests;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Queue\WorkerOptions;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use RuntimeException;
use Tiden\Laravel\QueueTimeoutCapture;
use Tiden\Laravel\Tests\Fixtures\FailingJob;
use Tiden\Laravel\Tests\Fixtures\NonKillingWorker;
use Tiden\Laravel\Tests\Fixtures\ThrowingBeforeSend;
use Tiden\Laravel\Tests\Fixtures\TimeoutJob;

final class QueueTimeoutCaptureTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('logging.default', 'null');
    }

    public function test_timed_out_job_sends_exactly_one_envelope(): void
    {
        if (! extension_loaded('pcntl')) {
            $this->markTestSkipped('The worker timeout handler needs ext-pcntl.');
        }

        $this->app['queue']->connection('database')->push(new TimeoutJob);
        $worker = $this->worker();
        $saved = $this->saveSignalHandlers();

        try {
            // daemon() is the path that arms SIGALRM; runNextJob() never does.
            $worker->daemon('database', 'default', new WorkerOptions(
                memory: 1024, timeout: 1, sleep: 0, maxTries: 1, stopWhenEmpty: true, maxJobs: 1,
            ));
        } finally {
            $this->restoreSignalHandlers($saved);
        }

        $this->assertCount(1, $this->transport->envelopes);
        $this->assertSame(TimeoutExceededException::class, $this->exceptionType(0));
        $this->assertNotEmpty($worker->kills, 'the timeout handler should have killed the worker');
    }

    public function test_job_that_throws_on_last_attempt_sends_exactly_one_envelope(): void
    {
        $this->app['queue']->connection('database')->push(new FailingJob);

        $this->worker()->runNextJob('database', 'default', new WorkerOptions(sleep: 0, maxTries: 1));

        // JobFailed fires and is ignored; the worker's own report() is the one send.
        $this->assertCount(1, $this->transport->envelopes);
        $this->assertSame(RuntimeException::class, $this->exceptionType(0));
    }

    public function test_job_failed_with_non_timeout_exception_sends_nothing(): void
    {
        $this->app['events']->dispatch(new JobFailed('database', $this->syncJob(), new RuntimeException('boom')));

        $this->assertCount(0, $this->transport->envelopes);
    }

    #[DefineEnvironment('disableTimeoutCapture')]
    public function test_capture_timeouts_can_be_disabled(): void
    {
        $job = $this->syncJob();
        $this->app['events']->dispatch(new JobFailed('database', $job, TimeoutExceededException::forJob($job)));

        $this->assertCount(0, $this->transport->envelopes);
        $this->assertNotContains(QueueTimeoutCapture::class, $this->listenerOwners(JobFailed::class));
    }

    public function test_job_failed_with_timeout_exception_is_captured(): void
    {
        $job = $this->syncJob();
        $this->app['events']->dispatch(new JobFailed('database', $job, TimeoutExceededException::forJob($job)));

        $this->assertCount(1, $this->transport->envelopes);
        $this->assertSame(TimeoutExceededException::class, $this->exceptionType(0));
    }

    #[DefineEnvironment('throwingBeforeSend')]
    public function test_listener_never_throws(): void
    {
        $job = $this->syncJob();

        // The capture path fails (before_send throws); the event dispatch, and
        // with it the worker's kill() after JobFailed, must still go through.
        $this->app['events']->dispatch(new JobFailed('database', $job, TimeoutExceededException::forJob($job)));

        $this->assertCount(0, $this->transport->envelopes);
    }

    protected function throwingBeforeSend($app): void
    {
        $app['config']->set('tiden.before_send', [ThrowingBeforeSend::class, 'handle']);
    }

    protected function disableTimeoutCapture($app): void
    {
        $app['config']->set('tiden.queue.capture_timeouts', false);
    }

    private function worker(): NonKillingWorker
    {
        return new NonKillingWorker(
            $this->app['queue'],
            $this->app['events'],
            $this->app->make(ExceptionHandler::class),
            static fn (): bool => false,
        );
    }

    private function syncJob(): SyncJob
    {
        return new SyncJob($this->app, (string) json_encode(['job' => 'x', 'displayName' => TimeoutJob::class, 'data' => []]), 'sync', 'default');
    }

    /**
     * daemon() installs process-wide signal handlers and turns async signals on.
     *
     * @return array{async: bool, handlers: array<int, mixed>}
     */
    private function saveSignalHandlers(): array
    {
        // The signals daemon() takes over (timeout, listenForSignals). Not a class
        // constant: SIG* are undefined without ext-pcntl.
        $handlers = [];
        foreach ([SIGALRM, SIGQUIT, SIGTERM, SIGINT, SIGUSR2, SIGCONT] as $signal) {
            $handlers[$signal] = pcntl_signal_get_handler($signal);
        }

        return ['async' => pcntl_async_signals(), 'handlers' => $handlers];
    }

    /** @param array{async: bool, handlers: array<int, mixed>} $saved */
    private function restoreSignalHandlers(array $saved): void
    {
        pcntl_alarm(0);
        foreach ($saved['handlers'] as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
        pcntl_async_signals($saved['async']);
    }

    private function exceptionType(int $index): string
    {
        $body = $this->decodeEnvelope($this->transport->envelopes[$index]);

        return (string) ($body['exception']['values'][0]['type'] ?? '');
    }
}
