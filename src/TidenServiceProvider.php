<?php

declare(strict_types=1);

namespace Tiden\Laravel;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
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
                'http_timeout' => self::resolveHttpTimeout($config['http_timeout'] ?? null, $this->app->runningInConsole()),
                'max_breadcrumbs' => self::resolveMaxBreadcrumbs($config['max_breadcrumbs'] ?? null),
                'on_transport_failure' => [TransportFailureLogger::class, 'log'],
            ];
            $beforeSend = $this->resolveBeforeSend($config['before_send'] ?? null);
            if ($beforeSend !== null) {
                $options['before_send'] = $beforeSend;
            }

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

            // Before Breadcrumbs: the per-job clear must run ahead of the job's
            // own "processing" crumb (same-event listeners run in order).
            if ((bool) ($config['reset_scope'] ?? true)) {
                UnitOfWorkScope::register($events);
            }

            $breadcrumbs = (array) ($config['breadcrumbs'] ?? []);
            Breadcrumbs::register($events, [
                'sql' => (bool) ($breadcrumbs['sql'] ?? true),
                'queue' => (bool) ($breadcrumbs['queue'] ?? true),
                'logs' => (bool) ($breadcrumbs['logs'] ?? true),
                'max_message_length' => self::resolveMaxMessageLength($breadcrumbs['max_message_length'] ?? null),
            ]);

            // Timed-out jobs never reach report(): the worker fails them and kills
            // the process. Other job failures are reported by the worker itself.
            $queue = (array) ($config['queue'] ?? []);
            if ((bool) ($queue['capture_timeouts'] ?? true)) {
                QueueTimeoutCapture::register($events);
            }

            // Opt-in: error-level log records that carry a reportable exception.
            $logs = (array) ($config['logs'] ?? []);
            if ((bool) ($logs['capture_exceptions'] ?? false)) {
                ErrorLogCapture::register($events, $handler);
            }
        }

        if ($this->app->runningInConsole()) {
            $this->publishes(
                [__DIR__.'/../config/tiden.php' => $this->app->configPath('tiden.php')],
                'tiden-config',
            );
        }
    }

    /**
     * A configured positive number wins; otherwise 5 s in the console (workers
     * and commands can afford to wait) and 2 s on the request path.
     *
     * @internal
     */
    public static function resolveHttpTimeout(mixed $configured, bool $console): float
    {
        if (is_numeric($configured) && (float) $configured > 0) {
            return (float) $configured;
        }

        return $console ? 5.0 : 2.0;
    }

    /**
     * A positive number wins; anything else (an empty env var casts to 0) means
     * the default of 100. Zero would silently drop every breadcrumb.
     *
     * @internal
     */
    public static function resolveMaxBreadcrumbs(mixed $configured): int
    {
        if (is_numeric($configured) && (int) $configured > 0) {
            return (int) $configured;
        }

        return 100;
    }

    /**
     * `0` is a deliberate "no limit"; null, '' (an empty env var) and anything
     * non-numeric or negative fall back to the default so a blank variable does
     * not switch truncation off.
     *
     * @internal
     */
    public static function resolveMaxMessageLength(mixed $configured): int
    {
        if (is_numeric($configured) && (int) $configured >= 0) {
            return (int) $configured;
        }

        return Breadcrumbs::DEFAULT_MAX_MESSAGE_LENGTH;
    }

    /**
     * `before_send` must survive `config:cache`, so only an array callable or the
     * class-string of an invokable is accepted; the class is resolved from the
     * container. Closures and anything else are ignored. A class the container
     * cannot build is logged and ignored too: monitoring must never stop the app
     * from booting.
     */
    private function resolveBeforeSend(mixed $configured): ?callable
    {
        if ($configured instanceof Closure) {
            return null;
        }

        if (is_array($configured)) {
            return is_callable($configured) ? $configured : null;
        }

        if (is_string($configured) && class_exists($configured)) {
            try {
                $instance = $this->app->make($configured);
            } catch (Throwable $e) {
                Log::warning('tiden.before_send.unresolvable', ['class' => $configured, 'error' => $e->getMessage()]);

                return null;
            }

            return is_callable($instance) ? $instance : null;
        }

        return null;
    }
}
