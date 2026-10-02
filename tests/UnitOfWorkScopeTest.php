<?php

declare(strict_types=1);

namespace Tiden\Laravel\Tests;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Console\Kernel as FoundationKernel;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tiden\Laravel\Tests\Fixtures\ScriptedJob;
use Tiden\Scope;
use Tiden\Sdk;

final class UnitOfWorkScopeTest extends TestCase
{
    public function test_previous_job_breadcrumbs_do_not_leak(): void
    {
        $this->queue(new ScriptedJob(log: 'first job log', query: 'first job query'));
        $this->queue(new ScriptedJob(log: 'second job log', throw: 'second job failed'));

        $this->work();
        $this->assertSame([], $this->transport->envelopes, 'the first job reports nothing');
        $this->work();

        $event = $this->lastEvent();
        $this->assertSame('second job failed', $event['exception']['values'][0]['value']);
        $messages = $this->breadcrumbMessages($event);
        $this->assertContains('second job log', $messages);
        foreach ($messages as $message) {
            $this->assertStringNotContainsString('first job', $message);
        }
    }

    public function test_failed_job_breadcrumbs_survive_until_report(): void
    {
        // maxTries 1: the throw is the last attempt, so JobFailed fires before
        // the worker reports. The report must still carry the job's trail.
        $this->queue(new ScriptedJob(log: 'doomed job log', query: 'doomed job query', throw: 'doomed'));

        $this->work();

        $this->assertCount(1, $this->transport->envelopes);
        $crumbs = $this->breadcrumbsOf($this->lastEvent());
        $messages = $this->breadcrumbMessages($this->lastEvent());
        $this->assertContains('doomed job log', $messages);
        $this->assertContains("select 'doomed job query' as q", $messages);
        $states = array_column(array_column($crumbs, 'data'), 'state');
        $this->assertContains('failed', $states, 'the JobFailed crumb is on the reported event');
    }

    public function test_job_tags_discarded_but_global_tags_survive(): void
    {
        Sdk::configureScope(static function (Scope $scope): void {
            $scope->setTag('global', 'kept');
        });

        $this->queue(new ScriptedJob(tag: 'inside', capture: 'during job'));
        $this->work();

        $during = $this->lastEvent();
        $this->assertSame('kept', $during['tags']['global'] ?? null);
        $this->assertSame('inside', $during['tags']['job'] ?? null);

        Sdk::captureMessage('after job');

        $after = $this->lastEvent();
        $this->assertSame('kept', $after['tags']['global'] ?? null);
        $this->assertArrayNotHasKey('job', $after['tags'] ?? []);
    }

    public function test_sync_job_keeps_request_breadcrumbs(): void
    {
        Log::info('request crumb');

        $this->app->make(Bus::class)->dispatch(
            (new ScriptedJob(log: 'sync job log', capture: 'inside sync job'))->onConnection('sync'),
        );

        $inside = $this->breadcrumbMessages($this->lastEvent());
        $this->assertContains('request crumb', $inside);
        $this->assertContains('sync job log', $inside);

        Sdk::captureMessage('after sync job');

        $after = $this->breadcrumbMessages($this->lastEvent());
        $this->assertContains('request crumb', $after);
        $this->assertContains('sync job log', $after);
    }

    public function test_sync_job_inside_a_queued_job_does_not_pop_its_scope(): void
    {
        $this->queue(new ScriptedJob(tag: 'outer', syncChild: 'child log', capture: 'after child'));

        $this->work();

        $event = $this->lastEvent();
        $this->assertSame('outer', $event['tags']['job'] ?? null, 'the queued job scope is still active');
        $this->assertContains('child log', $this->breadcrumbMessages($event));
    }

    public function test_nothing_pops_on_job_failed(): void
    {
        // The job sets a tag and fails on its last attempt: JobFailed and
        // JobExceptionOccurred fire, the worker reports, and the job's scope
        // stays pushed until the next job starts.
        $this->queue(new ScriptedJob(tag: 'failing', log: 'failing job log', throw: 'nope'));
        $this->work();

        Sdk::captureMessage('after failed job');
        $after = $this->lastEvent();
        $this->assertSame('failing', $after['tags']['job'] ?? null);
        $this->assertContains('failing job log', $this->breadcrumbMessages($after));

        // The next job pops the stale scope before pushing its own.
        $this->queue(new ScriptedJob(capture: 'next job'));
        $this->work();

        $next = $this->lastEvent();
        $this->assertArrayNotHasKey('job', $next['tags'] ?? []);
        $this->assertNotContains('failing job log', $this->breadcrumbMessages($next));

        Sdk::captureMessage('after next job');
        $this->assertArrayNotHasKey('job', $this->lastEvent()['tags'] ?? []);
    }

    public function test_top_level_command_clears_but_nested_does_not(): void
    {
        // Testbench keeps Symfony's console events off in unit tests; turn the
        // rerouting on and rebuild Artisan so CommandStarting really fires.
        $kernel = $this->app->make(Kernel::class);
        $this->assertInstanceOf(FoundationKernel::class, $kernel);
        $kernel->rerouteSymfonyCommandEvents();

        Artisan::command('tiden-test:outer', function (): void {
            Log::info('outer log');
            Artisan::call('tiden-test:inner');
        });
        Artisan::command('tiden-test:inner', function (): void {
            Log::info('inner log');
            Sdk::captureMessage('inside inner');
        });
        $kernel->setArtisan(null);

        Log::info('before command');
        Artisan::call('tiden-test:outer');

        $event = $this->lastEvent();
        $messages = $this->breadcrumbMessages($event);
        $this->assertNotContains('before command', $messages, 'the top-level command cleared the trail');
        $this->assertContains('outer log', $messages, 'the nested command did not clear it');
        $this->assertContains('inner log', $messages);
        $this->assertSame('tiden-test:outer', $event['tags']['command'] ?? null);
    }

    public function test_command_depth_follows_starting_and_finished_events(): void
    {
        $events = $this->app->make('events');
        $input = new ArrayInput([]);
        $output = new NullOutput;

        Log::info('before');
        $events->dispatch(new CommandStarting('first', $input, $output));
        Log::info('in first');
        $events->dispatch(new CommandStarting('nested', $input, $output));
        $events->dispatch(new CommandFinished('nested', $input, $output, 0));
        $events->dispatch(new CommandFinished('first', $input, $output, 0));

        Sdk::captureMessage('after first');
        $this->assertSame(['in first'], $this->breadcrumbMessages($this->lastEvent()));
        $this->assertSame('first', $this->lastEvent()['tags']['command'] ?? null);

        // Depth is back to 0: the next command is top-level again.
        $events->dispatch(new CommandStarting('second', $input, $output));
        Sdk::captureMessage('in second');
        $this->assertSame([], $this->breadcrumbMessages($this->lastEvent()));
        $this->assertSame('second', $this->lastEvent()['tags']['command'] ?? null);
    }

    private function queue(ScriptedJob $job): void
    {
        $this->app->make(Bus::class)->dispatch($job->onConnection('database'));
    }

    private function work(): void
    {
        $worker = new Worker(
            $this->app->make('queue'),
            $this->app->make('events'),
            $this->app->make(ExceptionHandler::class),
            static fn (): bool => false,
        );
        $worker->runNextJob('database', 'default', new WorkerOptions(maxTries: 1));
    }
}
