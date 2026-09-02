<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IAM\Catalog\ServiceCatalog;
use Amtgard\IdP\Utility\BuiltInOrkPolicyServices;
use PHPUnit\Framework\TestCase;

class BuiltInOrkPolicyServicesTest extends TestCase
{
    public function testServiceNamesMatchServiceCatalogEnum(): void
    {
        $expected = array_map(
            static fn (ServiceCatalog $service): string => $service->value,
            ServiceCatalog::cases()
        );

        $this->assertSame($expected, BuiltInOrkPolicyServices::serviceNames());
    }

    public function testIsBuiltInRecognizesEnumServices(): void
    {
        $this->assertTrue(BuiltInOrkPolicyServices::isBuiltIn(ServiceCatalog::Kingdom->value));
        $this->assertTrue(BuiltInOrkPolicyServices::isBuiltIn(ServiceCatalog::Idp->value));
        $this->assertTrue(BuiltInOrkPolicyServices::isBuiltIn(ServiceCatalog::ORK->value));
    }

    public function testIsBuiltInRejectsCustomIntegratorServices(): void
    {
        $this->assertFalse(BuiltInOrkPolicyServices::isBuiltIn('Skbc'));
        $this->assertFalse(BuiltInOrkPolicyServices::isBuiltIn('MyCustomApp'));
    }
}
