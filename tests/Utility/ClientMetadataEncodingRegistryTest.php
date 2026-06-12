<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IdP\Utility\ClientMetadata\Base64MetadataEncodingStrategy;
use Amtgard\IdP\Utility\ClientMetadata\ClientMetadataEncodingRegistry;
use Amtgard\IdP\Utility\ClientMetadata\JsonObjectMetadataEncodingStrategy;
use Amtgard\IdP\Utility\ClientMetadataValidator;
use PHPUnit\Framework\TestCase;

class ClientMetadataEncodingRegistryTest extends TestCase
{
    public function testResolveDefaultsToJsonStrategy(): void
    {
        $strategy = ClientMetadataEncodingRegistry::resolve(null);

        $this->assertInstanceOf(JsonObjectMetadataEncodingStrategy::class, $strategy);
    }

    public function testResolveAcceptsBase64Encoding(): void
    {
        $strategy = ClientMetadataEncodingRegistry::resolve(' BASE64 ');

        $this->assertInstanceOf(Base64MetadataEncodingStrategy::class, $strategy);
    }

    public function testResolveRejectsUnknownEncoding(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClientMetadataEncodingRegistry::resolve('xml');
    }

    public function testResolveJsonEncodingConstant(): void
    {
        $strategy = ClientMetadataEncodingRegistry::resolve(ClientMetadataValidator::ENCODING_JSON);

        $this->assertInstanceOf(JsonObjectMetadataEncodingStrategy::class, $strategy);
    }
}
