<?php

declare(strict_types=1);

namespace Tiden\Laravel\Tests\Fixtures;

use RuntimeException;

/** Registered as dontReport in tests, so the handler's shouldReport() is false. */
final class IgnoredLogException extends RuntimeException {}
