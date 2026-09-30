<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Services\Mail;

use Amtgard\IdP\Services\Mail\IntegRecordingOutboundMail;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Redis;

final class IntegRecordingOutboundMailTest extends TestCase
{
    public function testSendStoresCodeInRedisWithoutLoggingRawAddress(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('setex')
            ->with(
                IntegRecordingOutboundMail::redisKeyForEmail('player@example.com'),
                IntegRecordingOutboundMail::TTL_SECONDS,
                $this->callback(function (string $json): bool {
                    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

                    return ($payload['code'] ?? null) === '123456'
                        && str_contains((string) ($payload['text'] ?? ''), '123456');
                }),
            );

        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        $mail = new IntegRecordingOutboundMail($redis, $logger);
        $mail->send(
            'player@example.com',
            'Your Amtgard verification code',
            'Your Amtgard verification code is 123456. It expires in 10 minutes.',
        );

        $this->assertTrue($handler->hasInfoRecords());
        $record = $handler->getRecords()[0];
        $this->assertSame('mail.sent', $record['message']);
        $this->assertSame('integ-recording', $record['context']['carrier']);
        $this->assertArrayNotHasKey('code', $record['context']);
    }
}
