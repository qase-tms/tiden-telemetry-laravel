<?php

declare(strict_types=1);

namespace Tiden\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Throwable;
use Tiden\Sdk;
use Tiden\Transport\TransportInterface;

/**
 * Auto-discovered Laravel integration. Initializes the Tiden SDK from config and
 * registers a reportable callback so every exception Laravel reports is also sent
 * to Tiden — no changes to bootstrap/app.php required.
 */
final class TidenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tiden.php', 'tiden');
    }

    public function boot(): void
    {
        /** @var array<string,mixed> $config */
        $config = (array) $this->app->make(Repository::class)->get('tiden', []);
        $dsn = $config['dsn'] ?? null;

        if (is_string($dsn) && $dsn !== '') {
            // A container binding replaces the curl transport (tests, dry runs).
            $transport = $this->app->bound(TransportInterface::class)
                ? $this->app->make(TransportInterface::class)
                : null;

            $options = [
                'dsn' => $dsn,
                'release' => $config['release'] ?? null,
                'environment' => $config['environment'] ?? null,
                'send_default_pii' => (bool) ($config['send_default_pii'] ?? false),
            ];

            // Laravel owns global error handling, so the core SDK's own handlers
            // are disabled — capture flows through the reportable callback below.
            Sdk::init($options, captureGlobals: false, transport: $transport);

            // The framework/Collision handler exposes reportable(); guard for any
            // custom handler that doesn't.
            $handler = $this->app->make(ExceptionHandler::class);
            if (method_exists($handler, 'reportable')) {
                $handler->reportable(static function (Throwable $e): void {
                    Sdk::captureException($e);
                });
            }

            $events = $this->app->make(Dispatcher::class);

            $breadcrumbs = (array) ($config['breadcrumbs'] ?? []);
            Breadcrumbs::register($events, [
                'sql' => (bool) ($breadcrumbs['sql'] ?? true),
                'queue' => (bool) ($breadcrumbs['queue'] ?? true),
                'logs' => (bool) ($breadcrumbs['logs'] ?? true),
                'max_message_length' => (int) ($breadcrumbs['max_message_length'] ?? Breadcrumbs::DEFAULT_MAX_MESSAGE_LENGTH),
            ]);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes(
                [__DIR__.'/../config/tiden.php' => $this->app->configPath('tiden.php')],
                'tiden-config',
            );
        }
    }
}
