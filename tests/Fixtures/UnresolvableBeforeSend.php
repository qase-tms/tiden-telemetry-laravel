<?php

declare(strict_types=1);

namespace Tiden\Laravel\Tests\Fixtures;

/** A `before_send` class-string the container cannot build: its dependency is unbound. */
final class UnresolvableBeforeSend
{
    public function __construct(public readonly UnboundDependency $dependency) {}

    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    public function __invoke(array $event): array
    {
        $event['tags']['before_send'] = 'unresolvable';

        return $event;
    }
}
