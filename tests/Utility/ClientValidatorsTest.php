<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IdP\Utility\ClientMetadataValidator;
use Amtgard\IdP\Utility\IamServiceValidator;
use PHPUnit\Framework\TestCase;

class ClientValidatorsTest extends TestCase
{
    public function testClientMetadataMustBeObject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClientMetadataValidator::validate(['list', 'item']);
    }

    public function testClientMetadataMaxBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClientMetadataValidator::validate(['key' => str_repeat('x', 301)]);
    }

    public function testClientMetadataAcceptsSmallObject(): void
    {
        $metadata = ClientMetadataValidator::validate(['role' => 'editor', 'level' => 2]);
        $this->assertSame('editor', $metadata['role']);
    }

    public function testIamServiceValidationAcceptsCustomIdentifier(): void
    {
        $this->assertNull(IamServiceValidator::validate(null));
        $this->assertSame('Skbc', IamServiceValidator::validate('Skbc'));
    }

    public function testIamServiceRejectsBuiltInOrkServiceName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IamServiceValidator::validate('Documents');
    }

    public function testIamServiceRejectsIdp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IamServiceValidator::validate('Idp');
    }

    public function testIamServiceRejectsInvalidPrefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IamServiceValidator::validate('1bad');
    }
}
