<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IdP\Utility\Security\HttpBasicCredentialsParser;
use PHPUnit\Framework\TestCase;

class HttpBasicCredentialsParserTest extends TestCase
{
    public function testFromAuthorizationHeaderParsesValidBasicCredentials(): void
    {
        $header = 'Basic ' . base64_encode('client-id:client-secret');
        $optional = HttpBasicCredentialsParser::fromAuthorizationHeader($header);

        $this->assertTrue($optional->isPresent());
        $this->assertSame('client-id', $optional->get()->clientId);
        $this->assertSame('client-secret', $optional->get()->clientSecret);
    }

    public function testFromAuthorizationHeaderRejectsMissingHeader(): void
    {
        $this->assertFalse(HttpBasicCredentialsParser::fromAuthorizationHeader('')->isPresent());
        $this->assertFalse(HttpBasicCredentialsParser::fromAuthorizationHeader('Bearer token')->isPresent());
    }

    public function testFromAuthorizationHeaderRejectsMalformedPayload(): void
    {
        $this->assertFalse(
            HttpBasicCredentialsParser::fromAuthorizationHeader('Basic !!!')->isPresent()
        );
        $this->assertFalse(
            HttpBasicCredentialsParser::fromAuthorizationHeader('Basic ' . base64_encode('no-colon'))->isPresent()
        );
    }
}
