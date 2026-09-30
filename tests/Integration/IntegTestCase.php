<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

use Amtgard\IdP\Tests\Integration\Support\IntegFixtureReseeder;
use PHPUnit\Framework\TestCase;

abstract class IntegTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        IntegFixtureReseeder::reseedForTest();
    }
}
