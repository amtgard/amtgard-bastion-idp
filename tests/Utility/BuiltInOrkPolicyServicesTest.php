<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IAM\OrkServices;
use Amtgard\IdP\Utility\BuiltInOrkPolicyServices;
use PHPUnit\Framework\TestCase;

class BuiltInOrkPolicyServicesTest extends TestCase
{
    public function testServiceNamesMatchOrkServicesEnum(): void
    {
        $expected = array_map(
            static fn (OrkServices $service): string => $service->value,
            OrkServices::cases()
        );

        $this->assertSame($expected, BuiltInOrkPolicyServices::serviceNames());
    }

    public function testIsBuiltInRecognizesEnumServices(): void
    {
        $this->assertTrue(BuiltInOrkPolicyServices::isBuiltIn(OrkServices::Kingdom->value));
        $this->assertTrue(BuiltInOrkPolicyServices::isBuiltIn(OrkServices::Idp->value));
        $this->assertTrue(BuiltInOrkPolicyServices::isBuiltIn(OrkServices::ORK->value));
    }

    public function testIsBuiltInRejectsCustomIntegratorServices(): void
    {
        $this->assertFalse(BuiltInOrkPolicyServices::isBuiltIn('Skbc'));
        $this->assertFalse(BuiltInOrkPolicyServices::isBuiltIn('MyCustomApp'));
    }
}
