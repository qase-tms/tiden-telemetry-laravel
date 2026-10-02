<?php

declare(strict_types=1);

namespace Tiden\Laravel\Tests\Fixtures;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tiden\Scope;
use Tiden\Sdk;

/** A queued job whose steps the test chooses; each step leaves a visible trace. */
final class ScriptedJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ?string $tag = null,
        public ?string $log = null,
        public ?string $query = null,
        public ?string $syncChild = null,
        public ?string $capture = null,
        public ?string $throw = null,
    ) {}

    public function handle(Dispatcher $bus): void
    {
        if ($this->tag !== null) {
            $tag = $this->tag;
            Sdk::configureScope(static function (Scope $scope) use ($tag): void {
                $scope->setTag('job', $tag);
            });
        }
        if ($this->log !== null) {
            Log::info($this->log);
        }
        if ($this->query !== null) {
            DB::connection()->select("select '{$this->query}' as q");
        }
        if ($this->syncChild !== null) {
            $bus->dispatch((new self(log: $this->syncChild))->onConnection('sync'));
        }
        if ($this->capture !== null) {
            Sdk::captureMessage($this->capture);
        }
        if ($this->throw !== null) {
            throw new \RuntimeException($this->throw);
        }
    }
}
