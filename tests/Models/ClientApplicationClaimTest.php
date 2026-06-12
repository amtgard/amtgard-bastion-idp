<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Models;

use Amtgard\IAM\ClaimFactory;
use Amtgard\IAM\OrkServices;
use Amtgard\IdP\Models\Orn\ClientApplicationClaim;
use Amtgard\IdP\Utility\ClientApplicationFormatRegistry;
use Amtgard\IdP\Utility\OrnClaimRegistry;
use PHPUnit\Framework\TestCase;

class ClientApplicationClaimTest extends TestCase
{
    protected function tearDown(): void
    {
        ClientApplicationFormatRegistry::reset();
    }

    public function testClaimUsesRegisteredCustomFormatAndAcceptsWildcardResource(): void
    {
        ClientApplicationFormatRegistry::register('Skbc', ['tenant-id', OrkServices::Kingdom]);
        OrnClaimRegistry::registerForService('Skbc');

        $claim = ClaimFactory::createOrn('Skbc:9:8:Custom/Action');

        $this->assertInstanceOf(ClientApplicationClaim::class, $claim);
        $this->assertSame(9, $claim->getProviso('tenant-id')->getSegmentValue());
        $this->assertSame('Custom/Action', $claim->getResource()->toString());
    }
}
