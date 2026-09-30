<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Services\Mail;

use Amtgard\IdP\Services\Mail\SendGridOutboundMail;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SendGridOutboundMailTest extends TestCase
{
    public function testConstructorRejectsEmptyCredentials(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SendGridOutboundMail($this->createStub(LoggerInterface::class), '  ', 'from@example.com');
    }

    public function testConstructorRejectsEmptyFromAddress(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SendGridOutboundMail($this->createStub(LoggerInterface::class), 'sg-secret-key', ' ');
    }

    public function testSendPostsTheMailApiAndLogsHashNotBody(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logged = null;
        $logger->expects($this->once())
            ->method('info')
            ->with('mail.sent', $this->callback(function (array $context) use (&$logged): bool {
                $logged = $context;

                return true;
            }));

        $history = [];
        $mailer = new SendGridOutboundMail(
            $logger,
            'sg-secret-key',
            'from@example.com',
            $this->client(new MockHandler([new Response(202)]), $history),
        );
        $mailer->send('player@example.com', 'Subject', 'code 654321');

        $request = $history[0]['request'];
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('https://api.sendgrid.com/v3/mail/send', (string) $request->getUri());
        $this->assertSame('Bearer sg-secret-key', $request->getHeaderLine('Authorization'));
        $this->assertSame('player@example.com', $body['personalizations'][0]['to'][0]['email']);
        $this->assertSame('from@example.com', $body['from']['email']);
        $this->assertSame('Subject', $body['subject']);
        $this->assertSame('text/plain', $body['content'][0]['type']);
        $this->assertSame('code 654321', $body['content'][0]['value']);
        $this->assertSame('sendgrid', $logged['carrier']);
        $this->assertSame(hash('sha256', 'player@example.com'), $logged['sent_to_hash']);
        $this->assertSame(202, $logged['status']);
        $encoded = json_encode($logged);
        $this->assertStringNotContainsString('654321', $encoded);
        $this->assertStringNotContainsString('player@example.com', $encoded);
        $this->assertStringNotContainsString('sg-secret-key', $encoded);
    }

    public function testSendThrowsWhenSendGridRejects(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logged = null;
        $logger->expects($this->once())
            ->method('info')
            ->with('mail.failed', $this->callback(function (array $context) use (&$logged): bool {
                $logged = $context;

                return true;
            }));
        $history = [];
        $body = '{"errors":[{"message":"player@example.com code 654321"}]}';
        $mailer = new SendGridOutboundMail(
            $logger,
            'sg-secret-key',
            'from@example.com',
            $this->client(new MockHandler([new Response(400, [], $body)]), $history),
        );

        try {
            $mailer->send('player@example.com', 'Subject', 'code 654321');
            $this->fail('expected send to throw');
        } catch (\RuntimeException $e) {
            $this->assertSame('SendGrid mail send failed', $e->getMessage());
        }

        $this->assertSame(400, $logged['status']);
        $encoded = json_encode($logged);
        $this->assertStringNotContainsString('player@example.com', $encoded);
        $this->assertStringNotContainsString('654321', $encoded);
        $this->assertStringNotContainsString('sg-secret-key', $encoded);
    }

    public function testSendThrowsWhenTheApiCannotBeReached(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with('mail.failed', $this->anything());
        $request = new Request('POST', 'https://api.sendgrid.com/v3/mail/send');
        $history = [];
        $mailer = new SendGridOutboundMail(
            $logger,
            'sg-secret-key',
            'from@example.com',
            $this->client(new MockHandler([
                new ConnectException('connection refused', $request),
            ]), $history),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SendGrid mail send failed');
        $mailer->send('player@example.com', 'Subject', 'body');
    }

    /**
     * @param array<int, array<string, mixed>> $history
     */
    private function client(MockHandler $mock, array &$history): Client
    {
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        return new Client(['handler' => $stack, 'http_errors' => false]);
    }
}
