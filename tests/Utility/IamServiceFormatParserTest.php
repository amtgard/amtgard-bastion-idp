<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IAM\OrkServices;
use Amtgard\IdP\Utility\IamServiceFormatParser;
use PHPUnit\Framework\TestCase;

class IamServiceFormatParserTest extends TestCase
{
    public function testParseReturnsDefaultWhenNullOrEmpty(): void
    {
        $this->assertSame(IamServiceFormatParser::defaultFormat(), IamServiceFormatParser::parse(null));
        $this->assertSame(IamServiceFormatParser::defaultFormat(), IamServiceFormatParser::parse(''));
        $this->assertSame(IamServiceFormatParser::defaultFormat(), IamServiceFormatParser::parse('   '));
    }

    public function testParseNormalizesBuiltinOrkServicesLabels(): void
    {
        $format = IamServiceFormatParser::parse('["Configuration","Kingdom","EventInstance"]');

        $this->assertSame(
            [OrkServices::Configuration, OrkServices::Kingdom, OrkServices::EventInstance],
            $format
        );
    }

    public function testParseAcceptsCustomSlotNames(): void
    {
        $format = IamServiceFormatParser::parse('["tenant-id","org unit","event-series"]');

        $this->assertSame(['tenant-id', 'org unit', 'event-series'], $format);
    }

    public function testEncodeRoundTripsMixedBuiltinAndCustomSlots(): void
    {
        $format = [
            OrkServices::Configuration,
            'tenant-id',
            OrkServices::Kingdom,
        ];

        $encoded = IamServiceFormatParser::encode($format);

        $this->assertSame('["Configuration","tenant-id","Kingdom"]', $encoded);
        $this->assertSame($format, IamServiceFormatParser::parse($encoded));
    }

    public function testParseRejectsEmptySlotName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IamServiceFormatParser::parse('["Configuration",""]');
    }

    public function testParseRejectsEmptyArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IamServiceFormatParser::parse('[]');
    }

    public function testParseRejectsNonListJson(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IamServiceFormatParser::parse('{"Configuration":true}');
    }

    public function testParseRejectsNonStringEntries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IamServiceFormatParser::parse('[1,"Kingdom"]');
    }
}
