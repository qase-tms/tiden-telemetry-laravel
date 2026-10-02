<?php

declare(strict_types=1);

namespace Tiden\Laravel\Tests;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Tiden\Laravel\Breadcrumbs;
use Tiden\Laravel\Tests\Fixtures\BeforeSendHooks;
use Tiden\Laravel\Tests\Fixtures\InvokableBeforeSend;
use Tiden\Laravel\Tests\Fixtures\UnresolvableBeforeSend;
use Tiden\Laravel\TidenServiceProvider;
use Tiden\Laravel\TransportFailureLogger;
use Tiden\Laravel\UnitOfWorkScope;
use Tiden\Options;
use Tiden\Scope;
use Tiden\Sdk;

final class IntegrationTest extends TestCase
{
    /** @var array{0: class-string, 1: string}|class-string|null */
    private array|string|null $beforeSend = null;

    public function test_config_is_merged(): void
    {
        $this->assertSame('http://test@localhost/proj', config('tiden.dsn'));
        $this->assertSame('testing', config('tiden.environment'));
        $this->assertNull(config('tiden.http_timeout'));
        $this->assertSame(100, config('tiden.max_breadcrumbs'));
        $this->assertNull(config('tiden.before_send'));
        $this->assertTrue(config('tiden.reset_scope'));
        $this->assertSame(1024, config('tiden.breadcrumbs.max_message_length'));
    }

    public function test_reported_exception_is_captured_and_sent(): void
    {
        // The provider handed the TestCase's NullTransport to Sdk::init, so the
        // envelope the bridge would send is inspectable here.
        $this->app->make(ExceptionHandler::class)->report(new \RuntimeException('boom from laravel'));

        $this->assertCount(1, $this->transport->envelopes);
        $body = $this->lastEvent();
        $this->assertSame('php', $body['platform']);
        $this->assertSame('RuntimeException', $body['exception']['values'][0]['type']);
        $this->assertSame('boom from laravel', $body['exception']['values'][0]['value']);
    }

    #[DefineEnvironment('useThreeBreadcrumbs')]
    public function test_max_breadcrumbs_is_passed_through(): void
    {
        foreach (['one', 'two', 'three', 'four', 'five'] as $message) {
            Log::info($message);
        }

        Sdk::captureMessage('after five logs');

        $this->assertSame(['three', 'four', 'five'], $this->breadcrumbMessages($this->lastEvent()));
    }

    public function test_http_timeout_reaches_the_sdk_as_five_seconds_in_console(): void
    {
        // Testbench runs in the console and the config leaves http_timeout null.
        $this->assertSame(5.0, $this->sdkOptions()->httpTimeout);
    }

    #[DefineEnvironment('useHalfSecondTimeout')]
    public function test_configured_http_timeout_reaches_the_sdk(): void
    {
        $this->assertSame(0.5, $this->sdkOptions()->httpTimeout);
    }

    #[DefineEnvironment('useThreeBreadcrumbs')]
    public function test_max_breadcrumbs_reaches_the_sdk(): void
    {
        $this->assertSame(3, $this->sdkOptions()->maxBreadcrumbs);
    }

    #[DefineEnvironment('useEmptyMaxBreadcrumbs')]
    public function test_empty_max_breadcrumbs_falls_back_to_the_default(): void
    {
        $this->assertSame(100, $this->sdkOptions()->maxBreadcrumbs);
    }

    #[DefineEnvironment('useZeroMaxBreadcrumbs')]
    public function test_zero_max_breadcrumbs_falls_back_to_the_default(): void
    {
        Log::info('kept');
        Sdk::captureMessage('after a log');

        $this->assertSame(100, $this->sdkOptions()->maxBreadcrumbs);
        $this->assertContains('kept', $this->breadcrumbMessages($this->lastEvent()));
    }

    public function test_max_breadcrumbs_resolution(): void
    {
        $this->assertSame(3, TidenServiceProvider::resolveMaxBreadcrumbs('3'));
        $this->assertSame(100, TidenServiceProvider::resolveMaxBreadcrumbs(''));
        $this->assertSame(100, TidenServiceProvider::resolveMaxBreadcrumbs(0));
        $this->assertSame(100, TidenServiceProvider::resolveMaxBreadcrumbs(-5));
        $this->assertSame(100, TidenServiceProvider::resolveMaxBreadcrumbs(null));
        $this->assertSame(100, TidenServiceProvider::resolveMaxBreadcrumbs('abc'));
    }

    public function test_max_message_length_resolution(): void
    {
        $this->assertSame(16, TidenServiceProvider::resolveMaxMessageLength('16'));
        $this->assertSame(0, TidenServiceProvider::resolveMaxMessageLength(0), '0 stays "unlimited"');
        $this->assertSame(0, TidenServiceProvider::resolveMaxMessageLength('0'));
        $this->assertSame(1024, TidenServiceProvider::resolveMaxMessageLength(''), 'an empty env var is not "unlimited"');
        $this->assertSame(1024, TidenServiceProvider::resolveMaxMessageLength(null));
        $this->assertSame(1024, TidenServiceProvider::resolveMaxMessageLength(-1));
        $this->assertSame(1024, TidenServiceProvider::resolveMaxMessageLength('abc'));
    }

    #[DefineEnvironment('useUnresolvableBeforeSend')]
    public function test_unresolvable_before_send_is_ignored_and_the_app_still_boots(): void
    {
        Sdk::captureMessage('hello');

        $this->assertCount(1, $this->transport->envelopes, 'the event is still sent');
        $this->assertArrayNotHasKey('before_send', $this->lastEvent()['tags'] ?? []);
    }

    public function test_http_timeout_defaults_to_five_seconds_in_console(): void
    {
        $this->assertSame(5.0, TidenServiceProvider::resolveHttpTimeout(null, console: true));
        $this->assertSame(2.0, TidenServiceProvider::resolveHttpTimeout(null, console: false));
        $this->assertSame(2.0, TidenServiceProvider::resolveHttpTimeout('', console: false));
        $this->assertSame(5.0, TidenServiceProvider::resolveHttpTimeout('0', console: true));
        $this->assertSame(5.0, TidenServiceProvider::resolveHttpTimeout('soon', console: true));
        // TIDEN_HTTP_TIMEOUT arrives as a string from env().
        $this->assertSame(0.5, TidenServiceProvider::resolveHttpTimeout('0.5', console: true));
        $this->assertSame(3.0, TidenServiceProvider::resolveHttpTimeout(3, console: false));
    }

    public function test_before_send_accepts_array_callable_and_class_string(): void
    {
        $forms = [
            'array-callable' => [BeforeSendHooks::class, 'tagStatic'],
            'invokable' => InvokableBeforeSend::class,
        ];

        foreach ($forms as $expected => $beforeSend) {
            // before_send is read once at boot, so boot a fresh app per form.
            $this->beforeSend = $beforeSend;
            $this->refreshApplication();
            $this->transport = $this->resolveTransport();

            Sdk::captureMessage('hello');

            $this->assertSame($expected, $this->lastEvent()['tags']['before_send'] ?? null, $expected);
        }
    }

    #[DefineEnvironment('useClosureBeforeSend')]
    public function test_before_send_closure_is_ignored(): void
    {
        Sdk::captureMessage('hello');

        $this->assertCount(1, $this->transport->envelopes, 'a closure that would drop the event was not applied');
        $this->assertArrayNotHasKey('before_send', $this->lastEvent()['tags'] ?? []);
    }

    public function test_transport_failure_is_logged_once_without_recursion(): void
    {
        $failure = ['reason' => 'curl_error', 'status' => null, 'bytes' => 512, 'curl_errno' => 28];

        /** @var list<array<string,mixed>> $records */
        $records = [];
        // A listener that reports straight back into the logger, the way a log
        // channel shipping to Tiden would on a failing transport.
        Event::listen(MessageLogged::class, static function (MessageLogged $e) use (&$records, $failure): void {
            if ($e->message === TransportFailureLogger::MESSAGE) {
                $records[] = ['level' => $e->level, 'context' => $e->context];
                TransportFailureLogger::log($failure);
            }
        });

        TransportFailureLogger::log($failure);

        $this->assertCount(1, $records);
        $this->assertSame('debug', $records[0]['level']);
        $this->assertSame($failure, $records[0]['context']);
        $this->assertArrayNotHasKey('url', $records[0]['context']);
        $this->assertArrayNotHasKey('payload', $records[0]['context']);

        // The guard is released afterwards: the next failure is logged again.
        TransportFailureLogger::log($failure);
        $this->assertCount(2, $records);
    }

    public function test_sdk_transport_failures_reach_the_log(): void
    {
        /** @var list<array<string,mixed>> $contexts */
        $contexts = [];
        Event::listen(MessageLogged::class, static function (MessageLogged $e) use (&$contexts): void {
            if ($e->message === TransportFailureLogger::MESSAGE) {
                $contexts[] = $e->context;
            }
        });

        // Tags are never truncated by the SDK's size cap, so this event cannot
        // shrink below it: the client drops it and reports envelope_too_large.
        Sdk::configureScope(static function (Scope $scope): void {
            $scope->setTag('huge', str_repeat('x', 1_000_000));
        });
        Sdk::captureMessage('too big');

        $this->assertCount(0, $this->transport->envelopes);
        $this->assertCount(1, $contexts);
        $this->assertSame('envelope_too_large', $contexts[0]['reason'] ?? null);
    }

    #[DefineEnvironment('withoutDsn')]
    public function test_nothing_registers_without_dsn(): void
    {
        $this->assertNull(Sdk::getClient());

        foreach ([QueryExecuted::class, MessageLogged::class, JobProcessing::class, CommandStarting::class] as $event) {
            $owners = $this->listenerOwners($event);
            $this->assertNotContains(UnitOfWorkScope::class, $owners, $event);
            $this->assertNotContains(Breadcrumbs::class, $owners, $event);
        }
    }

    #[DefineEnvironment('withoutScopeReset')]
    public function test_reset_scope_can_be_disabled(): void
    {
        $this->assertNotContains(UnitOfWorkScope::class, $this->listenerOwners(JobProcessing::class));
        $this->assertNotContains(UnitOfWorkScope::class, $this->listenerOwners(CommandStarting::class));
        $this->assertContains(Breadcrumbs::class, $this->listenerOwners(JobProcessing::class));
    }

    protected function useHalfSecondTimeout($app): void
    {
        // As env('TIDEN_HTTP_TIMEOUT') would deliver it.
        $app['config']->set('tiden.http_timeout', '0.5');
    }

    protected function useThreeBreadcrumbs($app): void
    {
        $app['config']->set('tiden.max_breadcrumbs', 3);
    }

    protected function useEmptyMaxBreadcrumbs($app): void
    {
        // As env('TIDEN_MAX_BREADCRUMBS', 100) delivers a variable that is set but empty.
        $app['config']->set('tiden.max_breadcrumbs', '');
    }

    protected function useZeroMaxBreadcrumbs($app): void
    {
        $app['config']->set('tiden.max_breadcrumbs', 0);
    }

    protected function useUnresolvableBeforeSend($app): void
    {
        $app['config']->set('tiden.before_send', UnresolvableBeforeSend::class);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if ($this->beforeSend !== null) {
            $app['config']->set('tiden.before_send', $this->beforeSend);
        }
    }

    protected function useClosureBeforeSend($app): void
    {
        $app['config']->set('tiden.before_send', static fn (array $event): ?array => null);
    }

    protected function withoutDsn($app): void
    {
        $this->withDsn = false;
        $app['config']->set('tiden.dsn', null);
    }

    protected function withoutScopeReset($app): void
    {
        $app['config']->set('tiden.reset_scope', false);
    }

    /** The Options the provider passed to Sdk::init (Client keeps them private). */
    private function sdkOptions(): Options
    {
        $client = Sdk::getClient();
        $this->assertNotNull($client);

        $options = (new \ReflectionProperty($client, 'options'))->getValue($client);
        $this->assertInstanceOf(Options::class, $options);

        return $options;
    }
}
