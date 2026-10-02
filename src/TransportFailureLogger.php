<?php

declare(strict_types=1);

namespace Tiden\Laravel;

use Illuminate\Support\Facades\Log;

/**
 * Receives the SDK's `on_transport_failure` callback and writes one debug log
 * record. The SDK passes only the reason, HTTP status, envelope size and curl
 * errno; this never adds the ingest URL (it carries the DSN key) or the payload.
 *
 * Logging can lead straight back here (a log channel that reports to Tiden, a
 * `MessageLogged` listener that captures), so a static flag drops re-entrant
 * calls instead of looping.
 */
final class TransportFailureLogger
{
    public const MESSAGE = 'tiden.transport.send.failed';

    private static bool $logging = false;

    /** @param array{reason: string, status: int|null, bytes: int, curl_errno: int|null} $failure */
    public static function log(array $failure): void
    {
        if (self::$logging) {
            return;
        }

        self::$logging = true;
        try {
            Log::debug(self::MESSAGE, $failure);
        } finally {
            self::$logging = false;
        }
    }
}
