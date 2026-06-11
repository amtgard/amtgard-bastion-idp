<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IAM\ClaimFactory;
use Amtgard\IAM\Definitions\ORN\AttendanceClaim;
use Amtgard\IAM\Definitions\ORN\OrkClaim;
use Amtgard\IAM\ORN\OrnClassMap;
use Amtgard\IAM\OrkServices;
use Amtgard\IAM\RequirementFactory;
use Amtgard\IdP\Models\Orn\ClientApplicationClaim;
use Amtgard\IdP\Models\Orn\IdpClaim;
use Amtgard\IdP\Models\Orn\IdpRequirement;
use Amtgard\IdP\Utility\ClientApplicationFormatRegistry;
use Amtgard\IdP\Utility\IamServiceFormatParser;
use Amtgard\IdP\Utility\OrnClaimRegistry;
use PHPUnit\Framework\TestCase;

class OrnDefinitionsTest extends TestCase
{
    public function testStandardOrnDefinitionsPackageRegistersAttendanceAndOrk(): void
    {
        $this->assertSame(AttendanceClaim::class, OrnClassMap::getClaimClass(OrkServices::Attendance));
        $this->assertSame(OrkClaim::class, OrnClassMap::getClaimClass(OrkServices::ORK));
        $this->assertSame(
            AttendanceClaim::class,
            OrnClassMap::getClaimClass('Attendance')
        );
    }

    public function testIdpOrnDefinitionsRegisterOnAutoload(): void
    {
        $this->assertSame(IdpClaim::class, OrnClassMap::getClaimClass(OrkServices::Idp));
        $this->assertSame(IdpRequirement::class, OrnClassMap::getRequirementClass(OrkServices::Idp));

        $claim = ClaimFactory::createOrn('Idp:0::::IDP/EditClient');
        $this->assertInstanceOf(IdpClaim::class, $claim);

        $requirement = RequirementFactory::createOrn('Idp:0::::IDP/EditClient');
        $this->assertInstanceOf(IdpRequirement::class, $requirement);
    }

    public function testOrnClaimRegistryRegistersCustomServiceIdentifier(): void
    {
        ClientApplicationFormatRegistry::register('Skbc', IamServiceFormatParser::defaultFormat());
        OrnClaimRegistry::registerForService('Skbc');

        $claim = ClaimFactory::createOrn('Skbc:0::::MyResource/MyAction');
        $this->assertInstanceOf(ClientApplicationClaim::class, $claim);
        $this->assertSame('Skbc', $claim->getServiceIdentifier()->name);
    }

    public function testOrnClaimRegistryDoesNotOverrideStandardPackageDefinitions(): void
    {
        OrnClaimRegistry::registerForService(OrkServices::Attendance->value);

        $this->assertSame(AttendanceClaim::class, OrnClassMap::getClaimClass(OrkServices::Attendance));
    }

    public function testOrnClaimRegistryDoesNotRegisterBuiltInEnumNamesAsCustom(): void
    {
        OrnClaimRegistry::registerForService(OrkServices::Documents->value);

        $this->assertFalse(OrnClassMap::isRegistered(OrkServices::Documents->value));
    }
}
