<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Services;

use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\IdP\Services\OrkLinkTokenService;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrkLinkTokenServiceTest extends TestCase
{
    private Database $database;
    private OrkLinkTokenService $service;
    private string $secret;

    protected function setUp(): void
    {
        $this->secret = str_repeat('x', 32);
        $_ENV['IDP_ORK_SHARED_SECRET'] = $this->secret;
        $this->database = $this->createMock(Database::class);
        $this->service = new OrkLinkTokenService($this->database, $this->createMock(LoggerInterface::class));
    }

    public function testPeekClaimsReturnsClaimsForValidToken(): void
    {
        $jwt = $this->mintToken();

        $claims = $this->service->peekClaims($jwt);

        $this->assertSame([
            'mundane_id' => 123,
            'email' => 'user@example.com',
            'jti' => 'jti-abc',
        ], $claims);
    }

    public function testPeekClaimsRejectsExpiredToken(): void
    {
        $jwt = $this->mintToken(['exp' => time() - 3600]);

        $this->assertNull($this->service->peekClaims($jwt));
    }

    public function testPeekClaimsRejectsWrongIssuer(): void
    {
        $jwt = $this->mintToken(['iss' => 'wrong']);

        $this->assertNull($this->service->peekClaims($jwt));
    }

    public function testPeekClaimsRejectsWrongAudience(): void
    {
        $jwt = $this->mintToken(['aud' => 'wrong']);

        $this->assertNull($this->service->peekClaims($jwt));
    }

    public function testPeekClaimsRejectsInvalidSub(): void
    {
        $this->assertNull($this->service->peekClaims($this->mintToken(['sub' => '0'])));
        $this->assertNull($this->service->peekClaims($this->mintToken(['sub' => 'abc'])));
    }

    public function testConsumeJtiInsertsRow(): void
    {
        $this->database->expects($this->once())->method('clear');
        $this->database->expects($this->once())->method('__set')->with('jti', 'jti-1');
        $this->database->expects($this->once())->method('execute');

        $this->assertTrue($this->service->consumeJti('jti-1'));
    }

    public function testConsumeJtiReturnsFalseOnDuplicate(): void
    {
        $pdo = new \PDOException('Duplicate', 23000);
        $this->database->method('execute')->willThrowException($pdo);

        $this->assertFalse($this->service->consumeJti('jti-dup'));
    }

    public function testConsumeJtiRethrowsUnexpectedDatabaseErrors(): void
    {
        $this->database->method('execute')->willThrowException(new \PDOException('boom', 0));

        $this->expectException(\PDOException::class);
        $this->service->consumeJti('jti-err');
    }

    public function testCleanExpiredJtiSwallowsErrors(): void
    {
        $this->database->method('execute')->willThrowException(new \RuntimeException('db down'));

        $this->service->cleanExpiredJti();
        $this->addToAssertionCount(1);
    }

    public function testCleanExpiredJtiDeletesOldRows(): void
    {
        $this->database->expects($this->once())->method('clear');
        $this->database->expects($this->once())->method('execute');

        $this->service->cleanExpiredJti();
    }

    public function testPeekClaimsReturnsNullOnBadSignature(): void
    {
        $jwt = JWT::encode([
            'iss' => 'ork',
            'aud' => 'idp',
            'sub' => '123',
            'email' => 'user@example.com',
            'jti' => 'jti-bad',
            'exp' => time() + 900,
        ], str_repeat('y', 32), 'HS256');

        $this->assertNull($this->service->peekClaims($jwt));
    }

    public function testPeekClaimsReturnsNullWhenRequiredClaimsMissing(): void
    {
        $jwt = $this->mintToken(['jti' => '']);

        $this->assertNull($this->service->peekClaims($jwt));
    }

    public function testPeekClaimsUsesLegacySecretFallback(): void
    {
        unset($_ENV['IDP_ORK_SHARED_SECRET']);
        $_ENV['ORK_LINK_TOKEN_SECRET'] = str_repeat('z', 32);
        $service = new OrkLinkTokenService($this->database, $this->createMock(LoggerInterface::class));
        $jwt = JWT::encode([
            'iss' => 'ork',
            'aud' => 'idp',
            'sub' => '123',
            'email' => 'user@example.com',
            'jti' => 'jti-legacy',
            'exp' => time() + 900,
        ], str_repeat('z', 32), 'HS256');

        $claims = $service->peekClaims($jwt);

        $this->assertSame('user@example.com', $claims['email'] ?? null);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function mintToken(array $overrides = []): string
    {
        $now = time();
        $payload = array_merge([
            'iss' => 'ork',
            'aud' => 'idp',
            'sub' => '123',
            'email' => 'user@example.com',
            'jti' => 'jti-abc',
            'iat' => $now,
            'exp' => $now + 900,
        ], $overrides);

        return JWT::encode($payload, $this->secret, 'HS256');
    }
}
