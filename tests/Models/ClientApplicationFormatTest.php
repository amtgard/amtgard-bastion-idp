<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Models;

use Amtgard\IdP\Models\Orn\ClientApplicationFormat;
use PHPUnit\Framework\TestCase;

class ClientApplicationFormatTest extends TestCase
{
    public function testServiceFormatReturnsDefaultSlots(): void
    {
        $format = ClientApplicationFormat::serviceFormat();

        $this->assertCount(4, $format);
    }

    public function testGetValidResourceMapAllowsWildcardResources(): void
    {
        $this->assertSame(['*' => ['*']], ClientApplicationFormat::getValidResourceMap());
        $this->assertSame(['*' => ['*']], ClientApplicationFormat::getValidResourceMap('Officer'));
    }
}
