<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Services\Mailbox;

use Amtgard\IdP\Services\Mailbox\LogOutboundMail;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class LogOutboundMailTest extends TestCase
{
    public function testSendLogsHashAndSubjectNeverRawAddressOrBody(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $context = null;
        $logger->expects($this->once())
            ->method('info')
            ->with('mailbox.log.sent', $this->callback(function (array $logged) use (&$context): bool {
                $context = $logged;

                return true;
            }));

        (new LogOutboundMail($logger))->send('secret@example.com', 'Your Amtgard verification code', 'Your verification code is 123456');

        $this->assertSame(hash('sha256', 'secret@example.com'), $context['sent_to_hash']);
        $this->assertSame('Your Amtgard verification code', $context['subject']);
        $this->assertArrayNotHasKey('to', $context);
        $this->assertArrayNotHasKey('text', $context);
        $this->assertStringNotContainsString('secret@example.com', json_encode($context));
        $this->assertStringNotContainsString('123456', json_encode($context));
    }
}
