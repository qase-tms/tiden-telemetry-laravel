<?php

declare(strict_types=1);

namespace Tiden\Laravel\Tests;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
}
