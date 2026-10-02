<?php

declare(strict_types=1);

namespace Tiden\Laravel\Tests\Fixtures;

use LogicException;

/** `before_send` that blows up, to prove the capture listeners never throw. */
final class ThrowingBeforeSend
{
    /** @param array<string,mixed> $event */
    public static function handle(array $event): never
    {
        throw new LogicException('before_send failed');
    }
}
