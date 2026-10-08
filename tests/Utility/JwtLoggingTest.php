<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IdP\Tests\Support\FirebaseJwtTestFactory;
use Amtgard\IdP\Utility\Jwt;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class JwtLoggingTest extends TestCase
{
    private TestHandler $handler;

    private Logger $logger;

    protected function setUp(): void
    {
        FirebaseJwtTestFactory::ensureKeys();
        $this->handler = new TestHandler();
        $this->logger = new Logger('security');
        $this->logger->pushHandler($this->handler);
    }

    public function testValidateJwtLogsEmptyPayload(): void
    {
        $emptyPayload = 'eyJub3Q';
        $jwt = "header.{$emptyPayload}.signature";
        $this->assertFalse(Jwt::validateJwt($jwt, $jwt, $this->logger));
        $reasons = array_column(
            array_column($this->handler->getRecords(), 'context'),
            'reason'
        );
        $this->assertContains('empty_payload', $reasons);
    }

    public function testValidateJwtLogsMismatchReasons(): void
    {
        $payload1 = 'eyJhdWQiOiJhdWQtMSIsImlzcyI6Imlzcy0xIiwiZXhwIjo5OTk5OTk5OTk5fQ';
        $payload2 = 'eyJhdWQiOiJhdWQtMiIsImlzcyI6Imlzcy0xIiwiZXhwIjo5OTk5OTk5OTk5fQ';
        $jwt1 = "header.{$payload1}.signature";
        $jwt2 = "header.{$payload2}.signature";

        Jwt::validateJwt($jwt1, $jwt2, $this->logger);
        $this->assertSame('aud_mismatch', $this->handler->getRecords()[0]['context']['reason']);

        $this->handler->clear();
        $payload3 = 'eyJhdWQiOiJhdWQtMSIsImlzcyI6Imlzcy0yIiwiZXhwIjo5OTk5OTk5OTk5fQ';
        Jwt::validateJwt($jwt1, "header.{$payload3}.signature", $this->logger);
        $this->assertSame('iss_mismatch', $this->handler->getRecords()[0]['context']['reason']);

        $this->handler->clear();
        $expired = 'eyJhdWQiOiJhdWQtMSIsImlzcyI6Imlzcy0xIiwiZXhwIjoxMDAwfQ';
        Jwt::validateJwt("header.{$expired}.signature", $jwt1, $this->logger);
        $this->assertSame('exp_invalid', $this->handler->getRecords()[0]['context']['reason']);

        $this->handler->clear();
        $withPolicy = 'eyJhdWQiOiJhdWQtMSIsImlzcyI6Imlzcy0xIiwiZXhwIjo5OTk5OTk5OTk5LCJwb2xpY3kiOiJbXXkifQ';
        Jwt::validateJwt("header.{$withPolicy}.signature", $jwt1, $this->logger);
        $this->assertSame('policy_mismatch', $this->handler->getRecords()[0]['context']['reason']);
    }

    public function testParseJwtLogsMalformedCompact(): void
    {
        $this->assertNull(Jwt::parseJwt('not-three-parts', $this->logger));
        $this->assertSame('malformed_compact', $this->handler->getRecords()[0]['context']['reason']);
    }

    public function testValidateJwtRequestLogsMissingBearer(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->with('Authorization')->willReturn('');

        $this->assertNull(Jwt::validateJwtRequest($request, $this->logger));
        $this->assertSame('missing_bearer', $this->handler->getRecords()[0]['context']['reason']);
    }

    public function testValidateJwtSignatureLogsExceptionClass(): void
    {
        $jwt = FirebaseJwtTestFactory::encode(['exp' => time() + 3600]);
        $this->assertNull(Jwt::validateJwtSignature($jwt . 'tampered', $this->logger));
        $this->assertArrayHasKey('exception_class', $this->handler->getRecords()[0]['context']);
    }
}
