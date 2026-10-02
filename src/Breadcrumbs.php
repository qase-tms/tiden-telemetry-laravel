<?php

declare(strict_types=1);

namespace Tiden\Laravel;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Tiden\Breadcrumb;
use Tiden\Sdk;

/**
 * Records Laravel activity (SQL, queue jobs, logs) as breadcrumbs on the SDK's
 * scope, so they ride along with the next captured event. Each source is opt-out
 * via config. SQL bindings and log context are intentionally omitted (PII).
 *
 * SQL and log messages are cut to `max_message_length` bytes: a worker that
 * runs bulk inserts would otherwise carry megabytes of SQL text on one event,
 * past the ingest's 1 MiB envelope cap.
 */
final class Breadcrumbs
{
    public const DEFAULT_MAX_MESSAGE_LENGTH = 1024;

    /** @param array{sql?: bool, queue?: bool, logs?: bool, max_message_length?: int} $config */
    public static function register(Dispatcher $events, array $config): void
    {
        $limit = (int) ($config['max_message_length'] ?? self::DEFAULT_MAX_MESSAGE_LENGTH);

        if ($config['sql'] ?? true) {
            $events->listen(QueryExecuted::class, static function (QueryExecuted $e) use ($limit): void {
                Sdk::addBreadcrumb(new Breadcrumb(
                    message: self::cut($e->sql, $limit),
                    category: 'query',
                    type: 'query',
                    data: ['duration_ms' => $e->time, 'connection' => $e->connectionName],
                ));
            });
        }

        if ($config['queue'] ?? true) {
            $events->listen(JobProcessing::class, static function (JobProcessing $e): void {
                Sdk::addBreadcrumb(new Breadcrumb(
                    message: $e->job->resolveName(),
                    category: 'queue',
                    type: 'queue',
                    data: ['connection' => $e->connectionName, 'state' => 'processing'],
                ));
            });
            $events->listen(JobProcessed::class, static function (JobProcessed $e): void {
                Sdk::addBreadcrumb(new Breadcrumb(
                    message: $e->job->resolveName(),
                    category: 'queue',
                    type: 'queue',
                    data: ['state' => 'processed'],
                ));
            });
            $events->listen(JobFailed::class, static function (JobFailed $e): void {
                Sdk::addBreadcrumb(new Breadcrumb(
                    message: $e->job->resolveName(),
                    category: 'queue',
                    level: 'error',
                    type: 'queue',
                    data: ['connection' => $e->connectionName, 'state' => 'failed'],
                ));
            });
        }

        if ($config['logs'] ?? true) {
            $events->listen(MessageLogged::class, static function (MessageLogged $e) use ($limit): void {
                // The SDK's own failure report would otherwise fill the trail
                // with one crumb per undelivered event during a backoff.
                if ((string) $e->message === TransportFailureLogger::MESSAGE) {
                    return;
                }
                Sdk::addBreadcrumb(new Breadcrumb(
                    message: self::cut((string) $e->message, $limit),
                    category: 'log',
                    level: (string) $e->level,
                    type: 'log',
                ));
            });
        }
    }

    /** Cuts to at most $limit bytes without splitting a UTF-8 character; 0 = unlimited. */
    private static function cut(string $message, int $limit): string
    {
        if ($limit <= 0 || strlen($message) <= $limit) {
            return $message;
        }

        return mb_strcut($message, 0, $limit, 'UTF-8');
    }
}
