<?php

declare(strict_types=1);

namespace Tiden\Laravel;

use Throwable;
use WeakMap;

/**
 * Remembers the exceptions Laravel's exception handler has reported through the
 * bridge's reportable callback. Weak keys: an exception is forgotten as soon as
 * the host drops it.
 *
 * `ErrorLogCapture` consults this before `shouldReport()`, which is not free of
 * side effects (with `throttle()` it takes a rate-limiter slot on every call).
 * The handler logs every exception it reports, so without this the opt-in log
 * capture would spend a second slot per `report()`. It does not depend on the
 * framework's `isReporting()`, so it holds on any `ExceptionHandler`.
 */
final class ReportedExceptions
{
    /** @var WeakMap<Throwable, true>|null */
    private static ?WeakMap $seen = null;

    public static function mark(Throwable $e): void
    {
        self::map()[$e] = true;
    }

    public static function contains(Throwable $e): bool
    {
        return isset(self::map()[$e]);
    }

    /** Test/reset hook. */
    public static function reset(): void
    {
        self::$seen = null;
    }

    /** @return WeakMap<Throwable, true> */
    private static function map(): WeakMap
    {
        return self::$seen ??= new WeakMap;
    }
}
