<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Services\Mailbox;

use Amtgard\IdP\Services\Mailbox\SmtpOutboundMail;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SmtpOutboundMailTest extends TestCase
{
    public function testConstructorRejectsEmptyDsn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SmtpOutboundMail('  ', $this->createMock(LoggerInterface::class));
    }

    public function testSendUsesTransportAndLogsHashNotCode(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logged = null;
        $logger->expects($this->once())
            ->method('info')
            ->with('mailbox.smtp.sent', $this->callback(function (array $context) use (&$logged): bool {
                $logged = $context;

                return true;
            }));

        $seen = [];
        $mailer = new SmtpOutboundMail(
            'smtp://localhost:1025',
            $logger,
            function (string $to, string $subject, string $text) use (&$seen): bool {
                $seen = compact('to', 'subject', 'text');

                return true;
            },
        );
        $mailer->send('player@example.com', 'Subject', 'code 654321');

        $this->assertSame('player@example.com', $seen['to']);
        $this->assertSame(hash('sha256', 'player@example.com'), $logged['sent_to_hash']);
        $this->assertSame('Subject', $logged['subject']);
        $this->assertSame('smtp', $logged['dsn_scheme']);
        $this->assertStringNotContainsString('654321', json_encode($logged));
        $this->assertStringNotContainsString('player@example.com', json_encode($logged));
    }

    public function testSendThrowsWhenTransportFails(): void
    {
        $mailer = new SmtpOutboundMail(
            'smtp://localhost:1025',
            $this->createMock(LoggerInterface::class),
            static fn (): bool => false,
        );

        $this->expectException(\RuntimeException::class);
        $mailer->send('player@example.com', 'Subject', 'body');
    }

}
