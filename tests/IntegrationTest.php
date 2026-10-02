<?php

declare(strict_types=1);

namespace Tiden\Laravel\Tests;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Tiden\Laravel\Tests\Fixtures\BeforeSendHooks;
use Tiden\Laravel\Tests\Fixtures\InvokableBeforeSend;
use Tiden\Laravel\TidenServiceProvider;
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

    protected function useThreeBreadcrumbs($app): void
    {
        $app['config']->set('tiden.max_breadcrumbs', 3);
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
}
