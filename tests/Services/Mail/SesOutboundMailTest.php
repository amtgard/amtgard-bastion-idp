<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Services\Mail;

use Amtgard\IdP\Services\Mail\SesOutboundMail;
use Amtgard\IdP\Services\Mail\SmtpPipe;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SesOutboundMailTest extends TestCase
{
    public function testConstructorRejectsMissingCredentials(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SesOutboundMail($this->createStub(LoggerInterface::class), ' ', 'user', 'secret', 'from@example.com');
    }

    public function testConstructorRejectsAnInvalidPort(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SesOutboundMail(
            $this->createStub(LoggerInterface::class),
            'email-smtp.us-east-1.amazonaws.com',
            'user',
            'secret',
            'from@example.com',
            0,
        );
    }

    public function testSendAuthenticatesAndHidesSecretsFromTheLog(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logged = null;
        $logger->expects($this->once())
            ->method('info')
            ->with('mail.sent', $this->callback(function (array $context) use (&$logged): bool {
                $logged = $context;

                return true;
            }));
        $pipe = new ScriptedSmtpPipe([
            '220 ready',
            '250-hello',
            '220 ready tls',
            '250 hello',
            '334 VXNlcm5hbWU6',
            '334 UGFzc3dvcmQ6',
            '235 authenticated',
            '250 sender',
            '250 recipient',
            '354 go',
            '250 queued',
            '221 bye',
        ]);
        $mailer = $this->mailer($logger, $pipe);
        $mailer->send("player@example.com", "Sub\r\nject", ".code 654321\n.hidden");

        $written = implode('', $pipe->writes);
        $this->assertTrue($pipe->tls);
        $this->assertStringContainsString("STARTTLS\r\n", $written);
        $this->assertLessThan(strpos($written, "AUTH LOGIN\r\n"), strpos($written, "STARTTLS\r\n"));
        $this->assertStringContainsString(base64_encode('smtp-user') . "\r\n", $written);
        $this->assertStringContainsString(base64_encode('smtp-secret') . "\r\n", $written);
        $this->assertStringContainsString("MAIL FROM:<from@example.com>\r\n", $written);
        $this->assertStringContainsString("RCPT TO:<player@example.com>\r\n", $written);
        $this->assertStringContainsString("Subject: Subject\r\n", $written);
        $this->assertStringContainsString("..code 654321\r\n..hidden", $written);
        $this->assertStringContainsString("\r\n.\r\nQUIT\r\n", $written);
        $this->assertSame('ses', $logged['carrier']);
        $this->assertSame(hash('sha256', 'player@example.com'), $logged['sent_to_hash']);
        $encoded = json_encode($logged);
        $this->assertStringNotContainsString('smtp-secret', $encoded);
        $this->assertStringNotContainsString('player@example.com', $encoded);
        $this->assertStringNotContainsString('654321', $encoded);
    }

    public function testSendLogsTheSmtpStatusWhenRejected(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logged = null;
        $logger->expects($this->once())
            ->method('info')
            ->with('mail.failed', $this->callback(function (array $context) use (&$logged): bool {
                $logged = $context;

                return true;
            }));
        $pipe = new ScriptedSmtpPipe([
            '220 ready',
            '250 hello',
            '220 ready tls',
            '250 hello',
            '334 user',
            '334 pass',
            '535 authentication failed for smtp-secret',
        ]);
        $mailer = $this->mailer($logger, $pipe);

        try {
            $mailer->send('player@example.com', 'Subject', 'code 654321');
            $this->fail('expected send to throw');
        } catch (\RuntimeException $e) {
            $this->assertSame('SES mail send failed', $e->getMessage());
            $this->assertSame(535, $e->getCode());
        }

        $this->assertSame(535, $logged['status']);
        $encoded = json_encode($logged);
        $this->assertStringNotContainsString('smtp-secret', $encoded);
        $this->assertStringNotContainsString('player@example.com', $encoded);
    }

    public function testSendRejectsAnInvalidRecipient(): void
    {
        $mailer = $this->mailer($this->createStub(LoggerInterface::class), new ScriptedSmtpPipe([]));

        $this->expectException(\InvalidArgumentException::class);
        $mailer->send("bad\r\n@example.com", 'Subject', 'body');
    }

    private function mailer(LoggerInterface $logger, SmtpPipe $pipe): SesOutboundMail
    {
        return new SesOutboundMail(
            $logger,
            'email-smtp.us-east-1.amazonaws.com',
            'smtp-user',
            'smtp-secret',
            'from@example.com',
            587,
            $pipe,
        );
    }
}

final class ScriptedSmtpPipe implements SmtpPipe
{
    /** @var list<string> */
    public array $writes = [];

    public bool $tls = false;

    /** @param list<string> $responses */
    public function __construct(private array $responses)
    {
    }

    public function readResponse(): string
    {
        $next = array_shift($this->responses);
        if (!is_string($next)) {
            throw new \RuntimeException('no scripted response');
        }

        return $next;
    }

    public function write(string $data): void
    {
        $this->writes[] = $data;
    }

    public function startTls(): void
    {
        $this->tls = true;
    }
}
