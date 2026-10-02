<?php

declare(strict_types=1);

namespace Tiden\Laravel\Tests;

use Illuminate\Contracts\Debug\ExceptionHandler;

final class IntegrationTest extends TestCase
{
    public function test_config_is_merged(): void
    {
        $this->assertSame('http://test@localhost/proj', config('tiden.dsn'));
        $this->assertSame('testing', config('tiden.environment'));
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
}
