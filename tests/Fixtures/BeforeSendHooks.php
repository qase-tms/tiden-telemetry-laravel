<?php

declare(strict_types=1);

namespace Tiden\Laravel\Tests\Fixtures;

/** `before_send` given as an array callable: [BeforeSendHooks::class, 'tagStatic']. */
final class BeforeSendHooks
{
    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    public static function tagStatic(array $event): array
    {
        $event['tags']['before_send'] = 'array-callable';

        return $event;
    }
}
