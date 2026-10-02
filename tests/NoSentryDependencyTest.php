<?php

declare(strict_types=1);

namespace Tiden\Laravel\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/** Tiden must not depend on Sentry or reuse its code, not even as a suggestion. */
final class NoSentryDependencyTest extends BaseTestCase
{
    public function test_composer_json_and_src_never_mention_sentry(): void
    {
        $root = dirname(__DIR__);
        $files = [$root.'/composer.json'];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src', RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        $this->assertGreaterThan(1, count($files));
        foreach ($files as $path) {
            $this->assertDoesNotMatchRegularExpression('/sentry/i', (string) file_get_contents($path), $path);
        }
    }
}
