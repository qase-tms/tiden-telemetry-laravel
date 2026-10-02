<?php

declare(strict_types=1);

namespace Tiden\Laravel;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Tiden\Scope;
use Tiden\Sdk;

/**
 * Gives every queue job and every top-level Artisan command its own slate. A
 * long-running worker shares one static scope across thousands of jobs; without
 * this, an event from job N carries the breadcrumbs (and tags) of jobs 1..N-1.
 *
 * - `JobProcessing`: pop the previous job's scope if it is still pushed (it
 *   threw, so `JobProcessed` never came), push a fresh one, clear breadcrumbs.
 *   Tags, user and extra set before the job (global context) are inherited.
 * - `JobProcessed`: pop, so the job's tags do not outlive it.
 * - `JobFailed` / `JobExceptionOccurred`: nothing. The worker calls `report()`
 *   after them, and that event must still carry the job's breadcrumbs.
 * - `CommandStarting` at depth 0: clear breadcrumbs and tag `command`. A
 *   command called from inside another (`Artisan::call`) keeps the outer trail.
 *
 * Sync jobs run inline in the caller's unit of work and are left alone.
 *
 * Register before {@see Breadcrumbs}: same-event listeners run in registration
 * order, so the clear must happen before the job's "processing" crumb lands.
 */
final class UnitOfWorkScope
{
    private static bool $pushed = false;

    private static int $commandDepth = 0;

    public static function register(Dispatcher $events): void
    {
        $events->listen(JobProcessing::class, static function (JobProcessing $e): void {
            if ($e->job instanceof SyncJob) {
                return;
            }

            self::popIfPushed();
            Sdk::pushScope();
            self::$pushed = true;
            Sdk::configureScope(static function (Scope $scope): void {
                $scope->clearBreadcrumbs();
            });
        });

        $events->listen(JobProcessed::class, static function (JobProcessed $e): void {
            if ($e->job instanceof SyncJob) {
                return;
            }

            self::popIfPushed();
        });

        $events->listen(CommandStarting::class, static function (CommandStarting $e): void {
            if (self::$commandDepth++ > 0) {
                return;
            }

            $command = (string) $e->command;
            Sdk::configureScope(static function (Scope $scope) use ($command): void {
                $scope->clearBreadcrumbs();
                if ($command !== '') {
                    $scope->setTag('command', $command);
                }
            });
        });

        $events->listen(CommandFinished::class, static function (): void {
            self::$commandDepth = max(0, self::$commandDepth - 1);
        });
    }

    /** Test/reset hook: forgets the pushed-scope flag and the command depth. */
    public static function reset(): void
    {
        self::$pushed = false;
        self::$commandDepth = 0;
    }

    private static function popIfPushed(): void
    {
        if (self::$pushed) {
            Sdk::popScope();
            self::$pushed = false;
        }
    }
}
