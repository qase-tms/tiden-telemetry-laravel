<?php

declare(strict_types=1);

namespace Tiden\Laravel\Tests;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use LogicException;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use RuntimeException;
use Throwable;
use Tiden\Laravel\ErrorLogCapture;
use Tiden\Laravel\QueueTimeoutCapture;
use Tiden\Laravel\ReportedExceptions;
use Tiden\Laravel\Tests\Fixtures\IgnoredLogException;
use Tiden\Laravel\Tests\Fixtures\ThrowingBeforeSend;
use Tiden\Sdk;

final class ErrorLogCaptureTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('logging.default', 'null');
    }

    public function test_log_capture_is_off_by_default(): void
    {
        Log::error('x', ['exception' => new RuntimeException('logged only')]);

        $this->assertCount(0, $this->transport->envelopes);
        $this->assertNotContains(ErrorLogCapture::class, $this->listenerOwners(MessageLogged::class));
    }

    #[DefineEnvironment('enableLogCapture')]
    public function test_log_capture_on_sends_one(): void
    {
        Log::error('x', ['exception' => new RuntimeException('logged only')]);

        $this->assertCount(1, $this->transport->envelopes);
        $this->assertSame(RuntimeException::class, $this->lastEvent()['exception']['values'][0]['type'] ?? null);
    }

    #[DefineEnvironment('enableLogCapture')]
    public function test_report_with_log_capture_on_still_sends_one(): void
    {
        // The reportable callback captures, then the handler logs the same
        // Throwable at error level; the SDK sends it once.
        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('reported'));

        $this->assertCount(1, $this->transport->envelopes);
    }

    /**
     * shouldReport() takes a rate-limiter slot when the host throttles reports,
     * so the listener must not call it for the handler's own log record.
     */
    #[DefineEnvironment('enableLogCapture')]
    #[DefineEnvironment('arrayCache')]
    public function test_report_throttling_is_unchanged_with_log_capture_on(): void
    {
        $this->assertSame(2, $this->reportThreeThrottledToTwo());
    }

    #[DefineEnvironment('arrayCache')]
    public function test_report_throttling_with_log_capture_off(): void
    {
        $this->assertSame(2, $this->reportThreeThrottledToTwo());
    }

    #[DefineEnvironment('enableLogCapture')]
    public function test_reportable_callback_marks_the_exception_as_reported(): void
    {
        $e = new RuntimeException('reported once');

        $this->app->make(ExceptionHandler::class)->report($e);

        $this->assertTrue(ReportedExceptions::contains($e));
        $this->assertFalse(ReportedExceptions::contains(new RuntimeException('never reported')));
    }

    #[DefineEnvironment('enableLogCapture')]
    public function test_reported_exception_skips_should_report_without_is_reporting(): void
    {
        // Outside of report(), so isReporting() is false and only the bridge's
        // own record can short-circuit the listener.
        $calls = 0;
        $this->app->make(ExceptionHandler::class)->dontReportWhen(static function (Throwable $e) use (&$calls): bool {
            $calls++;

            return false;
        });
        $e = new RuntimeException('already reported');
        ReportedExceptions::mark($e);

        Log::error('logged again later', ['exception' => $e]);

        $this->assertCount(0, $this->transport->envelopes);
        $this->assertSame(0, $calls, 'shouldReport() must not run for a reported exception');
    }

    #[DefineEnvironment('enableLogCapture')]
    #[DefineEnvironment('throwingBeforeSend')]
    public function test_listener_never_throws(): void
    {
        // The SDK capture fails inside (before_send throws) ...
        Log::error('x', ['exception' => new RuntimeException('capture fails')]);

        // ... and the handler's shouldReport() itself throws.
        $this->app->make(ExceptionHandler::class)->dontReportWhen(static function (Throwable $e): bool {
            throw new LogicException('dontReportWhen failed');
        });
        Log::error('y', ['exception' => new RuntimeException('handler fails')]);

        $this->assertCount(0, $this->transport->envelopes);
    }

    #[DefineEnvironment('enableLogCapture')]
    public function test_non_reportable_exception_is_not_captured(): void
    {
        $this->app->make(ExceptionHandler::class)->dontReport(IgnoredLogException::class);

        Log::error('x', ['exception' => new IgnoredLogException('not reportable')]);

        $this->assertCount(0, $this->transport->envelopes);
    }

    #[DefineEnvironment('enableLogCapture')]
    public function test_info_level_with_exception_is_ignored(): void
    {
        Log::info('x', ['exception' => new RuntimeException('just info')]);
        Log::warning('x', ['exception' => new RuntimeException('just a warning')]);

        $this->assertCount(0, $this->transport->envelopes);
    }

    #[DefineEnvironment('enableLogCapture')]
    public function test_error_without_exception_context_is_ignored(): void
    {
        Log::error('x', ['exception' => 'not a throwable']);
        Log::critical('y');

        $this->assertCount(0, $this->transport->envelopes);
    }

    #[DefineEnvironment('withoutDsn')]
    public function test_nothing_registers_without_dsn(): void
    {
        $this->assertNotContains(QueueTimeoutCapture::class, $this->listenerOwners(JobFailed::class));
        $this->assertNotContains(ErrorLogCapture::class, $this->listenerOwners(MessageLogged::class));
        $this->assertNull(Sdk::getClient());

        Log::error('x', ['exception' => new RuntimeException('no dsn')]);
        $this->assertCount(0, $this->transport->envelopes);
    }

    #[DefineEnvironment('enableLogCapture')]
    public function test_both_integrations_register_with_a_dsn(): void
    {
        $this->assertContains(QueueTimeoutCapture::class, $this->listenerOwners(JobFailed::class));
        $this->assertContains(ErrorLogCapture::class, $this->listenerOwners(MessageLogged::class));
    }

    private function reportThreeThrottledToTwo(): int
    {
        $handler = $this->app->make(ExceptionHandler::class);
        $handler->throttleUsing(static fn (Throwable $e): Limit => Limit::perMinute(2));

        foreach (['one', 'two', 'three'] as $message) {
            $handler->report(new RuntimeException($message));
        }

        return count($this->transport->envelopes);
    }

    protected function arrayCache($app): void
    {
        $app['config']->set('cache.default', 'array');
    }

    protected function throwingBeforeSend($app): void
    {
        $app['config']->set('tiden.before_send', [ThrowingBeforeSend::class, 'handle']);
    }

    protected function enableLogCapture($app): void
    {
        $app['config']->set('tiden.logs.capture_exceptions', true);
    }

    protected function withoutDsn($app): void
    {
        // Both integrations opted in: the DSN guard alone must keep them off.
        $this->withDsn = false;
        $app['config']->set('tiden.dsn', null);
        $app['config']->set('tiden.queue.capture_timeouts', true);
        $app['config']->set('tiden.logs.capture_exceptions', true);
    }
}
