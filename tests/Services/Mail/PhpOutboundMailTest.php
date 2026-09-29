<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Services\Mail;

use Amtgard\IdP\Services\Mail\PhpOutboundMail;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PhpOutboundMailTest extends TestCase
{
    public function testSendUsesTransportAndLogsHashNotCode(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logged = null;
        $logger->expects($this->once())
            ->method('info')
            ->with('mail.sent', $this->callback(function (array $context) use (&$logged): bool {
                $logged = $context;

                return true;
            }));

        $seen = [];
        $mailer = new PhpOutboundMail(
            $logger,
            function (string $to, string $subject, string $text) use (&$seen): bool {
                $seen = compact('to', 'subject', 'text');

                return true;
            },
        );
        $mailer->send('player@example.com', 'Subject', 'code 654321');

        $this->assertSame('player@example.com', $seen['to']);
        $this->assertSame('php', $logged['carrier']);
        $this->assertSame(hash('sha256', 'player@example.com'), $logged['sent_to_hash']);
        $this->assertSame('Subject', $logged['subject']);
        $this->assertStringNotContainsString('654321', json_encode($logged));
        $this->assertStringNotContainsString('player@example.com', json_encode($logged));
    }

    public function testSendThrowsWhenTransportFails(): void
    {
        $mailer = new PhpOutboundMail(
            $this->createMock(LoggerInterface::class),
            static fn (): bool => false,
        );

        $this->expectException(\RuntimeException::class);
        $mailer->send('player@example.com', 'Subject', 'body');
    }
}
