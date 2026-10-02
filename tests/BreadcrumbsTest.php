<?php

declare(strict_types=1);

namespace Tiden\Laravel\Tests;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Tiden\Laravel\TransportFailureLogger;

final class BreadcrumbsTest extends TestCase
{
    public function test_sql_and_log_activity_becomes_breadcrumbs(): void
    {
        // Real Laravel activity -> events -> breadcrumbs on the scope.
        DB::connection()->select('select 1 as x');
        Log::info('hello from log');

        $this->app->make(ExceptionHandler::class)->report(new \RuntimeException('boom'));

        $crumbs = $this->breadcrumbsOf($this->lastEvent());

        $sql = array_filter($crumbs, static fn (array $c): bool => ($c['category'] ?? '') === 'query');
        $this->assertNotEmpty($sql, 'expected a SQL breadcrumb');
        $this->assertSame('select 1 as x', array_values($sql)[0]['message']);

        $logs = array_filter($crumbs, static fn (array $c): bool => ($c['category'] ?? '') === 'log' && str_contains($c['message'] ?? '', 'hello from log'));
        $this->assertNotEmpty($logs, 'expected a log breadcrumb');
    }

    #[DefineEnvironment('useShortMessages')]
    public function test_long_sql_and_log_messages_are_truncated(): void
    {
        DB::connection()->select("select '".str_repeat('x', 200)."' as x");
        Log::info(str_repeat('y', 200));
        // "€" is 3 bytes: 16 bytes would split the sixth one, so 5 survive (15 bytes).
        Log::info(str_repeat('€', 20));

        $this->app->make(ExceptionHandler::class)->report(new \RuntimeException('boom'));

        $crumbs = $this->breadcrumbsOf($this->lastEvent());
        $this->assertNotEmpty($crumbs);
        foreach ($crumbs as $crumb) {
            $this->assertLessThanOrEqual(16, strlen((string) $crumb['message']));
        }

        $messages = $this->breadcrumbMessages($this->lastEvent());
        $this->assertContains("select 'xxxxxxxx", $messages);
        $this->assertContains(str_repeat('y', 16), $messages);
        $this->assertContains(str_repeat('€', 5), $messages);
    }

    #[DefineEnvironment('useUnlimitedMessages')]
    public function test_zero_means_unlimited(): void
    {
        $sql = "select '".str_repeat('x', 3000)."' as x";
        DB::connection()->select($sql);
        Log::info(str_repeat('y', 3000));

        $this->app->make(ExceptionHandler::class)->report(new \RuntimeException('boom'));

        $messages = $this->breadcrumbMessages($this->lastEvent());
        $this->assertContains($sql, $messages);
        $this->assertContains(str_repeat('y', 3000), $messages);
    }

    public function test_default_limit_is_1024_bytes(): void
    {
        Log::info(str_repeat('y', 3000));

        $this->app->make(ExceptionHandler::class)->report(new \RuntimeException('boom'));

        $this->assertContains(str_repeat('y', 1024), $this->breadcrumbMessages($this->lastEvent()));
    }

    #[DefineEnvironment('useEmptyMessageLength')]
    public function test_empty_message_length_keeps_the_default_limit(): void
    {
        Log::info(str_repeat('y', 3000));

        $this->app->make(ExceptionHandler::class)->report(new \RuntimeException('boom'));

        $messages = $this->breadcrumbMessages($this->lastEvent());
        $this->assertContains(str_repeat('y', 1024), $messages);
        $this->assertNotContains(str_repeat('y', 3000), $messages);
    }

    public function test_transport_failure_log_is_not_recorded_as_a_breadcrumb(): void
    {
        TransportFailureLogger::log(['reason' => 'suppressed', 'status' => null, 'bytes' => 10, 'curl_errno' => null]);
        Log::info('a real log line');

        $this->app->make(ExceptionHandler::class)->report(new \RuntimeException('boom'));

        $messages = $this->breadcrumbMessages($this->lastEvent());
        $this->assertContains('a real log line', $messages);
        $this->assertNotContains(TransportFailureLogger::MESSAGE, $messages);
    }

    protected function useEmptyMessageLength($app): void
    {
        // As env('TIDEN_BREADCRUMBS_MAX_MESSAGE_LENGTH', 1024) delivers a variable that is set but empty.
        $app['config']->set('tiden.breadcrumbs.max_message_length', '');
    }

    protected function useShortMessages($app): void
    {
        $app['config']->set('tiden.breadcrumbs.max_message_length', 16);
    }

    protected function useUnlimitedMessages($app): void
    {
        $app['config']->set('tiden.breadcrumbs.max_message_length', 0);
    }
}
