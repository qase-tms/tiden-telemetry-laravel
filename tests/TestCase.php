<?php

declare(strict_types=1);

namespace Tiden\Laravel\Tests;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionFunction;
use Tiden\Laravel\TidenServiceProvider;
use Tiden\Laravel\UnitOfWorkScope;
use Tiden\Scope;
use Tiden\Sdk;
use Tiden\Transport\NullTransport;
use Tiden\Transport\TransportInterface;

abstract class TestCase extends Orchestra
{
    /** The in-memory transport the provider hands to Sdk::init (see defineEnvironment). */
    protected NullTransport $transport;

    /**
     * Set to false from a #[DefineEnvironment] method to boot without a DSN.
     * Testbench runs attribute methods before defineEnvironment(), so a config
     * value they set would be overwritten here; the flag works in either order.
     */
    protected bool $withDsn = true;

    protected function setUp(): void
    {
        // The SDK and the scope listeners keep static state; a test that boots
        // without a DSN must not see the previous test's client.
        Sdk::close();
        UnitOfWorkScope::reset();

        parent::setUp();

        $this->transport = $this->resolveTransport();

        // Start every test with an empty trail (the migrations left SQL crumbs).
        Sdk::configureScope(static function (Scope $scope): void {
            $scope->clearBreadcrumbs();
        });
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Sdk::close();
        UnitOfWorkScope::reset();
    }

    /** @return array<int,class-string> */
    protected function getPackageProviders($app): array
    {
        return [TidenServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Runs before providers boot, so the provider's Sdk::init picks it up.
        $app->singleton(TransportInterface::class, static fn (): NullTransport => new NullTransport);

        if ($this->withDsn) {
            $app['config']->set('tiden.dsn', 'http://test@localhost/proj');
        }
        $app['config']->set('tiden.environment', 'testing');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        // The jobs table, so real jobs can run through Illuminate\Queue\Worker.
        Schema::create('jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    /** The transport the current app's provider handed to Sdk::init. */
    protected function resolveTransport(): NullTransport
    {
        $transport = $this->app->make(TransportInterface::class);
        $this->assertInstanceOf(NullTransport::class, $transport);

        return $transport;
    }

    /**
     * The event body of the last envelope sent.
     *
     * @return array<string,mixed>
     */
    protected function lastEvent(): array
    {
        $envelope = $this->transport->last();
        $this->assertNotNull($envelope, 'expected an envelope to have been sent');

        return $this->decodeEnvelope($envelope);
    }

    /** @return array<string,mixed> */
    protected function decodeEnvelope(string $envelope): array
    {
        $body = json_decode(explode("\n", rtrim($envelope, "\n"))[2], true);
        $this->assertIsArray($body);

        return $body;
    }

    /**
     * @param  array<string,mixed>  $event
     * @return list<array<string,mixed>>
     */
    protected function breadcrumbsOf(array $event): array
    {
        return array_values($event['breadcrumbs']['values'] ?? []);
    }

    /**
     * @param  array<string,mixed>  $event
     * @return list<string>
     */
    protected function breadcrumbMessages(array $event): array
    {
        return array_map(static fn (array $c): string => (string) ($c['message'] ?? ''), $this->breadcrumbsOf($event));
    }

    /**
     * The class that registered each listener for $event, in registration
     * (= call) order. Closures report the class they were written in.
     *
     * @return list<string>
     */
    protected function listenerOwners(string $event): array
    {
        $events = $this->app->make('events');
        $this->assertInstanceOf(Dispatcher::class, $events);

        $owners = [];
        foreach ($events->getRawListeners()[$event] ?? [] as $listener) {
            $owners[] = match (true) {
                $listener instanceof Closure => (new ReflectionFunction($listener))->getClosureScopeClass()?->getName() ?? 'Closure',
                is_array($listener) => is_object($listener[0]) ? $listener[0]::class : (string) $listener[0],
                is_string($listener) => explode('@', $listener)[0],
                default => get_debug_type($listener),
            };
        }

        return $owners;
    }
}
