<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IdP\Utility\IamServiceFormatValidator;
use PHPUnit\Framework\TestCase;

class IamServiceFormatValidatorTest extends TestCase
{
    public function testValidateReturnsNullWhenUnset(): void
    {
        $this->assertNull(IamServiceFormatValidator::validate(null));
        $this->assertNull(IamServiceFormatValidator::validate(''));
        $this->assertNull(IamServiceFormatValidator::validate('   '));
    }

    public function testValidateNormalizesAndEncodesStoredFormat(): void
    {
        $encoded = IamServiceFormatValidator::validate('["Configuration","Kingdom"]');

        $this->assertSame('["Configuration","Kingdom"]', $encoded);
    }

    public function testValidateAcceptsCustomSlotNames(): void
    {
        $encoded = IamServiceFormatValidator::validate('["tenant-id","org unit"]');

        $this->assertSame('["tenant-id","org unit"]', $encoded);
    }

    public function testValidateRejectsInvalidFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IamServiceFormatValidator::validate('["Configuration",""]');
    }
}
