<?php

declare(strict_types=1);

namespace Tiden\Laravel\Tests\Fixtures;

/** Never bound in the container, so a class that needs it cannot be built. */
interface UnboundDependency {}
