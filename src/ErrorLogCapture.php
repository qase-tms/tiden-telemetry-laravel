<?php

declare(strict_types=1);

namespace Tiden\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Events\MessageLogged;
use Throwable;
use Tiden\Sdk;

/**
 * Captures exceptions that only reach the log: error-level (or higher) records
 * whose context carries an `exception` that the exception handler would report.
 *
 * Opt-in. Laravel's handler logs every exception it reports; that record is
 * skipped (isReporting()), and the SDK would send the same Throwable once anyway.
 */
final class ErrorLogCapture
{
    /** @var list<string> */
    private const LEVELS = ['error', 'critical', 'alert', 'emergency'];

    public static function register(Dispatcher $events, ExceptionHandler $handler): void
    {
        $events->listen(MessageLogged::class, static function (MessageLogged $event) use ($handler): void {
            // A log listener must never break logging.
            try {
                if (! in_array(strtolower((string) $event->level), self::LEVELS, true)) {
                    return;
                }

                $exception = $event->context['exception'] ?? null;
                if (! $exception instanceof Throwable) {
                    return;
                }

                // The handler's own log record for an exception it is reporting
                // right now: the reportable callback has already captured it.
                // Return before shouldReport(), which is not side-effect free: with
                // throttle() it takes a rate-limiter slot on every call.
                if (method_exists($handler, 'isReporting') && $handler->isReporting($exception)) {
                    return;
                }

                if (! $handler->shouldReport($exception)) {
                    return;
                }

                Sdk::captureException($exception);
            } catch (Throwable) {
                // Swallowed on purpose.
            }
        });
    }
}
