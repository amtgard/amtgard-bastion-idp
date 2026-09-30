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

    protected function integBaseUrl(): string
    {
        return (string) (getenv('IDP_BASE_URL') ?: 'http://localhost:37080');
    }
}
