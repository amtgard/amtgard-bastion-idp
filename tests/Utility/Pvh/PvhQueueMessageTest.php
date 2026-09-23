<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility\Pvh;

use Amtgard\IdP\Utility\Pvh\PvhQueueMessage;
use PHPUnit\Framework\TestCase;

final class PvhQueueMessageTest extends TestCase
{
    public function testEncodeAndFromJsonRoundTrip(): void
    {
        $json = PvhQueueMessage::encode('uuid-1', 'client-a');
        $parsed = PvhQueueMessage::fromJson($json);

        $this->assertTrue($parsed->isPresent());
        $message = $parsed->get();
        $this->assertSame('uuid-1', $message->getUserUuid());
        $this->assertSame('client-a', $message->getAud());
        $this->assertSame('uuid-1:client-a', $message->publishKey());
    }

    public function testFromJsonRejectsMalformedPayload(): void
    {
        $this->assertFalse(PvhQueueMessage::fromJson('not-json')->isPresent());
        $this->assertFalse(PvhQueueMessage::fromJson('{}')->isPresent());
        $this->assertFalse(PvhQueueMessage::fromJson('{"user_uuid":"","aud":"x"}')->isPresent());
    }
}
