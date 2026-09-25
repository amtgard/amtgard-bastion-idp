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
            'idp_email' => 'user@example.com',
            'challenge_id' => 'chal-1',
            'jti' => 'jti-abc',
        ], $claims);
    }

    public function testPeekLegacyClaimsAcceptsCurrentOrkHandoff(): void
    {
        $jwt = JWT::encode([
            'iss' => 'ork',
            'aud' => 'idp',
            'sub' => '123',
            'email' => 'user@example.com',
            'jti' => 'jti-legacy',
            'iat' => time(),
            'exp' => time() + 900,
        ], $this->secret, 'HS256');

        $this->assertSame([
            'mundane_id' => 123,
            'email' => 'user@example.com',
            'jti' => 'jti-legacy',
        ], $this->service->peekLegacyClaims($jwt));
        $this->assertNull($this->service->peekClaims($jwt));
    }

    public function testPeekLegacyClaimsRejectsMissingEmail(): void
    {
        $jwt = JWT::encode([
            'iss' => 'ork',
            'aud' => 'idp',
            'sub' => '123',
            'jti' => 'jti-legacy',
            'exp' => time() + 900,
        ], $this->secret, 'HS256');

        $this->assertNull($this->service->peekLegacyClaims($jwt));
    }

    public function testMintLegacyCompletionOmitsChallengeId(): void
    {
        $jwt = $this->service->mintLegacyCompletion('uuid-1', 44);
        $decoded = JWT::decode($jwt, new \Firebase\JWT\Key($this->secret, 'HS256'));

        $this->assertSame('idp', $decoded->iss);
        $this->assertSame('ork', $decoded->aud);
        $this->assertSame('uuid-1', $decoded->sub);
        $this->assertSame(44, $decoded->mundane_id);
        $this->assertObjectNotHasProperty('challenge_id', $decoded);
    }

    public function testPeekClaimsReturnsNullOnMalformedToken(): void
    {
        $this->assertNull($this->service->peekClaims('not-a-jwt'));
    }

    public function testPeekClaimsReturnsNullWhenSecretTooShort(): void
    {
        unset($_ENV['IDP_ORK_SHARED_SECRET'], $_ENV['ORK_LINK_TOKEN_SECRET']);

        $this->assertNull($this->service->peekClaims($this->mintToken()));
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
            'idp_email' => 'user@example.com',
            'challenge_id' => 'chal-1',
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

    public function testPeekClaimsAcceptsEmailAliasAsDestinationHint(): void
    {
        $jwt = $this->mintToken([
            'idp_email' => null,
            'email' => 'hint@example.com',
        ]);

        $claims = $this->service->peekClaims($jwt);

        $this->assertSame('hint@example.com', $claims['idp_email'] ?? null);
        $this->assertSame(123, $claims['mundane_id'] ?? null);
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
            'idp_email' => 'user@example.com',
            'challenge_id' => 'chal-1',
            'jti' => 'jti-legacy',
            'exp' => time() + 900,
        ], str_repeat('z', 32), 'HS256');

        $claims = $service->peekClaims($jwt);

        $this->assertSame('user@example.com', $claims['idp_email'] ?? null);
    }

    public function testPeekFlowACompletionRequiresPurposeAndIds(): void
    {
        $jwt = JWT::encode([
            'iss' => 'ork',
            'aud' => 'idp',
            'challenge_id' => 'chal-a',
            'idp_user_id' => 'uuid-1',
            'mundane_id' => 77,
            'purpose' => 'claim_ork',
            'jti' => 'jti-c',
            'exp' => time() + 120,
        ], $this->secret, 'HS256');

        $this->assertSame([
            'challenge_id' => 'chal-a',
            'idp_user_id' => 'uuid-1',
            'mundane_id' => 77,
            'purpose' => 'claim_ork',
            'jti' => 'jti-c',
        ], $this->service->peekFlowACompletion($jwt));
        $this->assertNull($this->service->peekFlowACompletion($this->mintToken()));
    }

    public function testMintAndRedirectHelpers(): void
    {
        $_ENV['ORK_BASE_URL'] = 'https://ork.example.com/orkui/';
        $handoff = $this->service->mintFlowAHandoff('uuid-1', 'chal-a');
        $completion = $this->service->mintFlowBCompletion('uuid-1', 9, 'chal-b');
        $decodedHandoff = JWT::decode($handoff, new \Firebase\JWT\Key($this->secret, 'HS256'));
        $decodedCompletion = JWT::decode($completion, new \Firebase\JWT\Key($this->secret, 'HS256'));

        $this->assertSame('idp', $decodedHandoff->iss);
        $this->assertSame('ork', $decodedHandoff->aud);
        $this->assertSame('uuid-1', $decodedHandoff->sub);
        $this->assertSame('chal-a', $decodedHandoff->challenge_id);
        $this->assertSame('claim_idp', $decodedCompletion->purpose);
        $this->assertTrue($this->service->hasSharedSecret());
        $this->assertSame(
            'https://ork.example.com/orkui/index.php?Route=Login/claim_ork&t=' . urlencode($handoff) . '&username=Hero',
            $this->service->flowAClaimRedirectUrl($handoff, 'Hero'),
        );
        $this->assertSame(
            'https://ork.example.com/orkui/index.php?Route=Login/idp_link_complete&t=' . urlencode($completion),
            $this->service->flowBCompletionRedirectUrl($completion),
        );
    }

    public function testOrkBaseUrlValidation(): void
    {
        unset($_ENV['ORK_BASE_URL']);
        try {
            $this->service->orkBaseUrl();
            $this->fail('expected missing base url to throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ORK_BASE_URL is not set', $e->getMessage());
        }

        $_ENV['ORK_BASE_URL'] = 'not-a-url';
        $this->expectException(\RuntimeException::class);
        $this->service->orkBaseUrl();
    }

    public function testOrkBaseUrlRejectsNonHttpScheme(): void
    {
        $_ENV['ORK_BASE_URL'] = 'ftp://ork.example.com';
        $this->expectException(\RuntimeException::class);
        $this->service->orkBaseUrl();
    }

    public function testHasSharedSecretFalseWhenShort(): void
    {
        unset($_ENV['IDP_ORK_SHARED_SECRET'], $_ENV['ORK_LINK_TOKEN_SECRET']);
        $this->assertFalse($this->service->hasSharedSecret());
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
            'idp_email' => 'user@example.com',
            'challenge_id' => 'chal-1',
            'jti' => 'jti-abc',
            'iat' => $now,
            'exp' => $now + 900,
        ], $overrides);

        return JWT::encode($payload, $this->secret, 'HS256');
    }
}
