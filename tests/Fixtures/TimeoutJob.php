<?php

declare(strict_types=1);

namespace Tiden\Laravel\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Runs past a one-second worker timeout. */
final class TimeoutJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        sleep(2);
    }
}
