<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IAM\Catalog\ServiceCatalog;
use Amtgard\IdP\Utility\ClientApplicationFormatRegistry;
use Amtgard\IdP\Utility\IamServiceFormatParser;
use PHPUnit\Framework\TestCase;

class ClientApplicationFormatRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        ClientApplicationFormatRegistry::reset();
    }

    public function testGetReturnsDefaultWhenServiceIsUnknown(): void
    {
        $this->assertSame(
            IamServiceFormatParser::defaultFormat(),
            ClientApplicationFormatRegistry::get('UnknownService')
        );
        $this->assertFalse(ClientApplicationFormatRegistry::has('UnknownService'));
    }

    public function testRegisterAndGetStoresBuiltinAndCustomSlots(): void
    {
        $format = [ServiceCatalog::Configuration, 'tenant-id', ServiceCatalog::Kingdom];
        ClientApplicationFormatRegistry::register('Skbc', $format);

        $this->assertTrue(ClientApplicationFormatRegistry::has('Skbc'));
        $this->assertSame($format, ClientApplicationFormatRegistry::get('Skbc'));
    }

    public function testResetClearsRegisteredFormats(): void
    {
        ClientApplicationFormatRegistry::register('Skbc', ['tenant-id']);
        ClientApplicationFormatRegistry::reset();

        $this->assertFalse(ClientApplicationFormatRegistry::has('Skbc'));
    }
}
