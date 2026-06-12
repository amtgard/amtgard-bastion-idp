<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Models;

use Amtgard\IdP\Models\AllowedLinkOrkProfileClientIds;
use PHPUnit\Framework\TestCase;

class AllowedLinkOrkProfileClientIdsTest extends TestCase
{
    public function testContainsParsesCommaSeparatedEnvList(): void
    {
        $_ENV['LINK_ORK_PROFILE_ALLOWED_CLIENT_IDS'] = ' client-a , client-b ';

        $allowed = new AllowedLinkOrkProfileClientIds();

        $this->assertTrue($allowed->contains('client-a'));
        $this->assertTrue($allowed->contains('client-b'));
        $this->assertFalse($allowed->contains('client-c'));
        $this->assertSame(['client-a', 'client-b'], $allowed->all());
    }
}
