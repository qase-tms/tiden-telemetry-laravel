<?php

declare(strict_types=1);

namespace Tiden\Laravel;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\TimeoutExceededException;
use Tiden\Sdk;

/**
 * Captures queue jobs that fail because they ran past their timeout.
 *
 * The worker's timeout handler (SIGALRM) fails the job and then kills the
 * process without calling ExceptionHandler::report(), so the reportable
 * callback never sees a timeout. JobFailed is the last hook that fires before
 * the kill. Every other final failure is reported by the worker itself, so it
 * is ignored here to avoid sending it twice.
 */
final class QueueTimeoutCapture
{
    public static function register(Dispatcher $events): void
    {
        $events->listen(JobFailed::class, static function (JobFailed $event): void {
            if (! $event->exception instanceof TimeoutExceededException) {
                return;
            }

            // The worker kills the process right after this event. The SDK's
            // capture never throws, so a failed send cannot stop that from happening.
            Sdk::captureException($event->exception);
        });
    }
}
