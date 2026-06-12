<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IdP\Utility\ClientMetadata\Base64MetadataEncodingStrategy;
use Amtgard\IdP\Utility\ClientMetadataValidator;
use PHPUnit\Framework\TestCase;

class Base64MetadataEncodingStrategyTest extends TestCase
{
    private Base64MetadataEncodingStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new Base64MetadataEncodingStrategy();
    }

    public function testEncodingName(): void
    {
        $this->assertSame(ClientMetadataValidator::ENCODING_BASE64, $this->strategy->encodingName());
    }

    public function testPrepareAcceptsValidBase64JsonObject(): void
    {
        $payload = base64_encode(json_encode(['role' => 'editor'], JSON_THROW_ON_ERROR));

        $prepared = $this->strategy->prepare($payload);

        $this->assertSame($payload, $prepared['payload']);
        $this->assertSame(ClientMetadataValidator::ENCODING_BASE64, $prepared['encoding']);
    }

    public function testPrepareRejectsNonString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->strategy->prepare(['not', 'a', 'string']);
    }

    public function testPrepareRejectsEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->strategy->prepare('   ');
    }

    public function testPrepareRejectsInvalidBase64(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->strategy->prepare('not-valid-base64!!!');
    }

    public function testPrepareRejectsJsonList(): void
    {
        $payload = base64_encode('["item"]');

        $this->expectException(\InvalidArgumentException::class);
        $this->strategy->prepare($payload);
    }

    public function testPrepareRejectsOversizedPayload(): void
    {
        $payload = base64_encode(json_encode(['key' => str_repeat('x', 301)], JSON_THROW_ON_ERROR));

        $this->expectException(\InvalidArgumentException::class);
        $this->strategy->prepare($payload);
    }
}
