<?php

declare(strict_types=1);

namespace Tiden\Laravel\Tests\Fixtures;

use Illuminate\Queue\Worker;

/** A real worker whose kill() records the call instead of ending the test process. */
final class NonKillingWorker extends Worker
{
    /** @var list<int> */
    public array $kills = [];

    public function kill($status = 0, $options = null, $reason = null, $connectionName = null, $queue = null)
    {
        $this->kills[] = (int) $status;
    }
}
