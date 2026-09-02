<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IAM\ClaimFactory;
use Amtgard\IAM\Definitions\ORN\AttendanceClaim;
use Amtgard\IAM\Definitions\ORN\OrkClaim;
use Amtgard\IAM\ORN\OrnClassMap;
use Amtgard\IAM\Catalog\ServiceCatalog;
use Amtgard\IAM\RequirementFactory;
use Amtgard\IdP\Models\Orn\ClientApplicationClaim;
use Amtgard\IdP\Models\Orn\IdpClaim;
use Amtgard\IdP\Models\Orn\IdpRequirement;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Utility\ClientApplicationFormatRegistry;
use Amtgard\IdP\Utility\IamServiceFormatParser;
use Amtgard\IdP\Utility\OrnClaimRegistry;
use PHPUnit\Framework\TestCase;

class OrnDefinitionsTest extends TestCase
{
    protected function tearDown(): void
    {
        ClientApplicationFormatRegistry::reset();
    }

    public function testStandardOrnDefinitionsPackageRegistersAttendanceAndOrk(): void
    {
        $this->assertSame(AttendanceClaim::class, OrnClassMap::getClaimClass(ServiceCatalog::Attendance));
        $this->assertSame(OrkClaim::class, OrnClassMap::getClaimClass(ServiceCatalog::ORK));
        $this->assertSame(
            AttendanceClaim::class,
            OrnClassMap::getClaimClass('Attendance')
        );
    }

    public function testIdpOrnDefinitionsRegisterOnAutoload(): void
    {
        $this->assertSame(IdpClaim::class, OrnClassMap::getClaimClass(ServiceCatalog::Idp));
        $this->assertSame(IdpRequirement::class, OrnClassMap::getRequirementClass(ServiceCatalog::Idp));

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
        $this->assertSame('Skbc', $claim->getPrefix()->name);
    }

    public function testOrnClaimRegistrySupportsCustomProvisoSlotNames(): void
    {
        ClientApplicationFormatRegistry::register('Skbc', ['tenant-id', ServiceCatalog::Kingdom]);
        OrnClaimRegistry::registerForService('Skbc');

        $claim = ClaimFactory::createOrn('Skbc:42:99:MyResource/MyAction');
        $this->assertInstanceOf(ClientApplicationClaim::class, $claim);
        $this->assertSame(42, $claim->getSegment('tenant-id')->getValue());
        $this->assertSame(99, $claim->getSegment(ServiceCatalog::Kingdom)->getValue());
    }

    public function testOrnClaimRegistryRegisterForClientLoadsStoredFormat(): void
    {
        $client = new class extends Client {
            public function getIamService(): ?string
            {
                return 'Skbc';
            }

            public function getIamServiceFormat(): ?string
            {
                return '["tenant-id","Kingdom"]';
            }
        };

        OrnClaimRegistry::registerForClient($client);

        $this->assertTrue(ClientApplicationFormatRegistry::has('Skbc'));
        $this->assertSame(
            ['tenant-id', ServiceCatalog::Kingdom],
            ClientApplicationFormatRegistry::get('Skbc')
        );
        $this->assertSame(ClientApplicationClaim::class, OrnClassMap::getClaimClass('Skbc'));

        $claim = ClaimFactory::createOrn('Skbc:7:8:MyResource/MyAction');
        $this->assertInstanceOf(ClientApplicationClaim::class, $claim);
        $this->assertSame(7, $claim->getSegment('tenant-id')->getValue());
        $this->assertSame(8, $claim->getSegment(ServiceCatalog::Kingdom)->getValue());
    }

    public function testOrnClaimRegistryRegisterForClientSkipsWhenIamServiceMissing(): void
    {
        $client = new class extends Client {
            public function getIamService(): ?string
            {
                return null;
            }

            public function getIamServiceFormat(): ?string
            {
                return '["tenant-id"]';
            }
        };

        OrnClaimRegistry::registerForClient($client);

        $this->assertFalse(ClientApplicationFormatRegistry::has('Skbc'));
    }

    public function testOrnClaimRegistryRegisterForServiceSkipsIdpNamespace(): void
    {
        OrnClaimRegistry::registerForService(ServiceCatalog::Idp->value);

        $this->assertSame(IdpClaim::class, OrnClassMap::getClaimClass(ServiceCatalog::Idp));
    }

    public function testOrnClaimRegistryDoesNotOverrideStandardPackageDefinitions(): void
    {
        OrnClaimRegistry::registerForService(ServiceCatalog::Attendance->value);

        $this->assertSame(AttendanceClaim::class, OrnClassMap::getClaimClass(ServiceCatalog::Attendance));
    }

    public function testOrnClaimRegistryDoesNotRegisterBuiltInEnumNamesAsCustom(): void
    {
        OrnClaimRegistry::registerForService(ServiceCatalog::Documents->value);

        $this->assertFalse(OrnClassMap::isRegistered(ServiceCatalog::Documents->value));
    }
}
