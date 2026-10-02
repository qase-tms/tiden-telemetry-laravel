<?php

declare(strict_types=1);

namespace Tiden\Laravel\Tests\Fixtures;

/** `before_send` given as a class-string; the provider resolves it from the container. */
final class InvokableBeforeSend
{
    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    public function __invoke(array $event): array
    {
        $event['tags']['before_send'] = 'invokable';

        return $event;
    }
}
