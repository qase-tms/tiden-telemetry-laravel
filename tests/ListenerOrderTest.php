<?php

declare(strict_types=1);

namespace Tiden\Laravel\Tests;

use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Log;
use Tiden\Laravel\Breadcrumbs;
use Tiden\Laravel\Tests\Fixtures\ScriptedJob;
use Tiden\Laravel\UnitOfWorkScope;

final class ListenerOrderTest extends TestCase
{
    public function test_unit_of_work_scope_listeners_run_before_breadcrumbs(): void
    {
        // Same-event listeners run in registration order (Illuminate\Events\Dispatcher).
        foreach ([JobProcessing::class, JobProcessed::class] as $event) {
            $owners = $this->listenerOwners($event);
            $scope = array_search(UnitOfWorkScope::class, $owners, true);
            $crumbs = array_search(Breadcrumbs::class, $owners, true);
            $this->assertIsInt($scope, "UnitOfWorkScope listens to {$event}");
            $this->assertIsInt($crumbs, "Breadcrumbs listens to {$event}");
            $this->assertLessThan($crumbs, $scope, "UnitOfWorkScope runs first on {$event}");
        }

        // Behaviour: the clear ran before the job's "processing" crumb was added,
        // so that crumb opens the job's trail and nothing from before survives.
        Log::info('before the job');
        $this->app->make(Bus::class)->dispatch((new ScriptedJob(capture: 'in job'))->onConnection('database'));
        (new Worker(
            $this->app->make('queue'),
            $this->app->make('events'),
            $this->app->make(ExceptionHandler::class),
            static fn (): bool => false,
        ))->runNextJob('database', 'default', new WorkerOptions(maxTries: 1));

        $crumbs = $this->breadcrumbsOf($this->lastEvent());
        $this->assertCount(1, $crumbs);
        $this->assertSame('queue', $crumbs[0]['category'] ?? null);
        $this->assertSame(ScriptedJob::class, $crumbs[0]['message'] ?? null);
        $this->assertSame('processing', $crumbs[0]['data']['state'] ?? null);
    }
}
