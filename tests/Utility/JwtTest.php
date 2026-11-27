<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IdP\Tests\Support\FirebaseJwtTestFactory;
use Amtgard\IdP\Tests\Support\OidcFixtureKeys;
use Amtgard\IdP\Utility\Jwt;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

class JwtTest extends TestCase
{
    protected function setUp(): void
    {
        FirebaseJwtTestFactory::ensureKeys();
    }

    public function testGetBearerJwt(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer my-token-123');

        $this->assertSame('my-token-123', Jwt::getBearerJwt($request));

        $request2 = $this->createMock(ServerRequestInterface::class);
        $request2->expects($this->once())
            ->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('InvalidHeader');

        $this->assertNull(Jwt::getBearerJwt($request2));
    }

    public function testParseJwt(): void
    {
        // A dummy JWT string: header.payload.signature
        // payload = {"sub":"123","aud":"test-aud","iss":"test-iss","exp":9999999999}
        // base64url of payload is
        // eyJzdWIiOiIxMjMiLCJhdWQiOiJ0ZXN0LWF1ZCIsImlzcyI6InRlc3QtaXNzIiwiZXhwIjo5OTk5OTk5OTk5fQ
        $payload = 'eyJzdWIiOiIxMjMiLCJhdWQiOiJ0ZXN0LWF1ZCIsImlzcyI6InRlc3QtaXNzIiwiZXhwIjo5OTk5OTk5OTk5fQ';
        $jwt = "header.{$payload}.signature";

        $parsed = Jwt::parseJwt($jwt);
        $this->assertIsArray($parsed);
        $this->assertSame('test-aud', $parsed['aud']);

        $parsedWithBearer = Jwt::parseJwt("Bearer {$jwt}");
        $this->assertIsArray($parsedWithBearer);
        $this->assertSame('test-iss', $parsedWithBearer['iss']);

        $invalidJwt = 'invalid-jwt-without-three-parts';
        $this->assertNull(Jwt::parseJwt($invalidJwt));
    }

    public function testValidateJwtAudienceMismatch(): void
    {
        $payload1 = 'eyJhdWQiOiJhdWQtMSIsImlzcyI6Imlzcy0xIiwiZXhwIjo5OTk5OTk5OTk5fQ'; // aud-1, iss-1, exp-9999999999
        $payload2 = 'eyJhdWQiOiJhdWQtMiIsImlzcyI6Imlzcy0xIiwiZXhwIjo5OTk5OTk5OTk5fQ'; // aud-2, iss-1

        $jwt1 = "header.{$payload1}.signature";
        $jwt2 = "header.{$payload2}.signature";

        $this->assertFalse(Jwt::validateJwt($jwt1, $jwt2));
    }

    public function testValidateJwtIssuerMismatch(): void
    {
        $payload1 = 'eyJhdWQiOiJhdWQtMSIsImlzcyI6Imlzcy0xIiwiZXhwIjo5OTk5OTk5OTk5fQ'; // aud-1, iss-1, exp-9999999999
        $payload2 = 'eyJhdWQiOiJhdWQtMSIsImlzcyI6Imlzcy0yIiwiZXhwIjo5OTk5OTk5OTk5fQ'; // aud-1, iss-2

        $jwt1 = "header.{$payload1}.signature";
        $jwt2 = "header.{$payload2}.signature";

        $this->assertFalse(Jwt::validateJwt($jwt1, $jwt2));
    }

    public function testValidateJwtExpiredOrMissingExp(): void
    {
        // exp = 1000 (past)
        $payload1 = 'eyJhdWQiOiJhdWQtMSIsImlzcyI6Imlzcy0xIiwiZXhwIjoxMDAwfQ';
        $jwt1 = "header.{$payload1}.signature";

        $payload2 = 'eyJhdWQiOiJhdWQtMSIsImlzcyI6Imlzcy0xIiwiZXhwIjo5OTk5OTk5OTk5fQ';
        $jwt2 = "header.{$payload2}.signature";

        // Expired challenge
        $this->assertFalse(Jwt::validateJwt($jwt1, $jwt2));

        // Missing exp challenge
        $payloadNoExp = 'eyJhdWQiOiJhdWQtMSIsImlzcyI6Imlzcy0xfQ';
        $jwtNoExp = "header.{$payloadNoExp}.signature";
        $this->assertFalse(Jwt::validateJwt($jwtNoExp, $jwt2));
    }

    public function testValidateJwtPolicyMismatch(): void
    {
        // Challenge with policy, user data with null policy
        $payload1 = 'eyJhdWQiOiJhdWQtMSIsImlzcyI6Imlzcy0xIiwiZXhwIjo5OTk5OTk5OTk5LCJwb2xpY3kiOiJbXXlifQ';
        $payload2 = 'eyJhdWQiOiJhdWQtMSIsImlzcyI6Imlzcy0xIiwiZXhwIjo5OTk5OTk5OTk5fQ';

        $jwt1 = "header.{$payload1}.signature";
        $jwt2 = "header.{$payload2}.signature";

        $this->assertFalse(Jwt::validateJwt($jwt1, $jwt2));
    }

    public function testValidateJwtSuccessWithMatchingPolicies(): void
    {
        // Same policy JSON
        $payload1 = 'eyJhdWQiOiJhdWQtMSIsImlzcyI6Imlzcy0xIiwiZXhwIjo5OTk5OTk5OTk5LCJwb2xpY3kiOiJbXXkifQ';
        $jwt1 = "header.{$payload1}.signature";

        $this->assertTrue(Jwt::validateJwt($jwt1, $jwt1));
    }

    public function testValidateJwtOnePolicyNull(): void
    {
        // Challenge policy is null, user policy is not null
        $payload1 = 'eyJhdWQiOiJhdWQtMSIsImlzcyI6Imlzcy0xIiwiZXhwIjo5OTk5OTk5OTk5fQ';
        $payload2 = 'eyJhdWQiOiJhdWQtMSIsImlzcyI6Imlzcy0xIiwiZXhwIjo5OTk5OTk5OTk5LCJwb2xpY3kiOiJbXXkifQ';

        $jwt1 = "header.{$payload1}.signature";
        $jwt2 = "header.{$payload2}.signature";

        $this->assertFalse(Jwt::validateJwt($jwt1, $jwt2));
    }

    public function testValidateJwtDifferentPoliciesNotMatching(): void
    {
        // Policy JSON is different (one allows EditClient, one allows something else)
        // {"aud":"aud-1","iss":"iss-1","exp":9999999999,"policy":"[\"Idp:0::::IDP/EditClient\"]"}
        $payload1 = 'eyJhdWQiOiJhdWQtMSIsImlzcyI6Imlzcy0xIiwiZXhwIjo5OTk5OTk5OTk5LCJwb2xpY3kiOiJb'
            . 'XCJJZHA6MDo6OjpJRFAvRWRpdENsaWVudFwiXSJ9';

        // {"aud":"aud-1","iss":"iss-1","exp":9999999999,"policy":"[\"Idp:0::::IDP/EditIdentity\"]"}
        $payload2 = 'eyJhdWQiOiJhdWQtMSIsImlzcyI6Imlzcy0xIiwiZXhwIjo5OTk5OTk5OTk5LCJwb2xpY3kiOiJb'
            . 'XCJJZHA6MDo6OjpJRFAvRWRpdElkZW50aXR5XCJdIn0';

        $jwt1 = "header.{$payload1}.signature";
        $jwt2 = "header.{$payload2}.signature";

        $this->assertFalse(Jwt::validateJwt($jwt1, $jwt2));
    }

    public function testValidateJwtInvalidTokens(): void
    {
        $this->assertFalse(Jwt::validateJwt('invalid', 'invalid'));
    }

    public function testValidateJwtSignatureStaticIsGone(): void
    {
        // M5 (§8): the static Jwt::validateJwtSignature() is deleted; the
        // injected AuthorizationJwtValidator owns signature validation now.
        $this->assertFalse(method_exists(Jwt::class, 'validateJwtSignature'));
    }

    public function testValidateJwtRequest(): void
    {
        try {
            FirebaseJwtTestFactory::assertKeysAvailable();
        } catch (\RuntimeException) {
            $this->markTestSkipped('Keys are missing');
        }

        $jwtStr = FirebaseJwtTestFactory::encode(
            [
            'iss' => FirebaseJwtTestFactory::DEFAULT_ISSUER,
            'aud' => 'client-1',
            'exp' => time() + 3600,
            ]
        );

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn("Bearer {$jwtStr}");

        $this->assertSame($jwtStr, Jwt::validateJwtRequest($request));

        // Invalid signature / modified JWT
        $tampered = $this->createMock(ServerRequestInterface::class);
        $tampered->method('getHeaderLine')->with('Authorization')->willReturn('Bearer ' . $jwtStr . 'invalid');
        $this->assertNull(Jwt::validateJwtRequest($tampered));

        // Test validateJwtRequest with missing header
        $request2 = $this->createMock(ServerRequestInterface::class);
        $request2->expects($this->once())
            ->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('');
        $this->assertNull(Jwt::validateJwtRequest($request2));
    }

    public function testValidateJwtRequestAcceptsEs256AndLegacyRs256DuringOverlap(): void
    {
        FirebaseJwtTestFactory::ensureKeys();
        $savedEnv = $_ENV['OAUTH_PUBLIC_KEY'] ?? null;
        $savedPrevious = $_ENV['OAUTH_PUBLIC_KEY_PREVIOUS'] ?? null;
        $savedPutenv = getenv('OAUTH_PUBLIC_KEY');
        $savedPutenvPrevious = getenv('OAUTH_PUBLIC_KEY_PREVIOUS');
        $_ENV['OAUTH_PUBLIC_KEY'] = OidcFixtureKeys::publicEcKeyPath();
        $_ENV['OAUTH_PUBLIC_KEY_PREVIOUS'] = '/tmp/public.key';
        putenv('OAUTH_PUBLIC_KEY=' . OidcFixtureKeys::publicEcKeyPath());
        putenv('OAUTH_PUBLIC_KEY_PREVIOUS=/tmp/public.key');

        try {
            $es256 = FirebaseJwtTestFactory::encodeEs256([
                'iss' => FirebaseJwtTestFactory::DEFAULT_ISSUER,
                'aud' => 'client-1',
                'exp' => time() + 3600,
            ]);
            $rs256 = FirebaseJwtTestFactory::encode([
                'iss' => FirebaseJwtTestFactory::DEFAULT_ISSUER,
                'aud' => 'client-1',
                'exp' => time() + 3600,
            ]);

            $this->assertSame($es256, Jwt::validateJwtRequest($this->bearerRequest($es256)));
            $this->assertSame($rs256, Jwt::validateJwtRequest($this->bearerRequest($rs256)));
            $this->assertNull(Jwt::validateJwtRequest($this->bearerRequest($es256 . 'tampered')));
            $this->assertNull(Jwt::validateJwtRequest($this->bearerRequest($this->noneToken())));
        } finally {
            if ($savedEnv === null) {
                unset($_ENV['OAUTH_PUBLIC_KEY']);
            } else {
                $_ENV['OAUTH_PUBLIC_KEY'] = $savedEnv;
            }
            if ($savedPrevious === null) {
                unset($_ENV['OAUTH_PUBLIC_KEY_PREVIOUS']);
            } else {
                $_ENV['OAUTH_PUBLIC_KEY_PREVIOUS'] = $savedPrevious;
            }
            if ($savedPutenv === false) {
                putenv('OAUTH_PUBLIC_KEY');
            } else {
                putenv('OAUTH_PUBLIC_KEY=' . $savedPutenv);
            }
            if ($savedPutenvPrevious === false) {
                putenv('OAUTH_PUBLIC_KEY_PREVIOUS');
            } else {
                putenv('OAUTH_PUBLIC_KEY_PREVIOUS=' . $savedPutenvPrevious);
            }
        }
    }

    private function bearerRequest(string $jwt): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->with('Authorization')->willReturn('Bearer ' . $jwt);

        return $request;
    }

    private function noneToken(): string
    {
        return $this->base64Url((string) json_encode(['alg' => 'none', 'typ' => 'JWT']))
            . '.' . $this->base64Url((string) json_encode([
                'iss' => FirebaseJwtTestFactory::DEFAULT_ISSUER,
                'aud' => 'client-1',
                'sub' => 'user-1',
                'exp' => time() + 3600,
            ]))
            . '.';
    }

    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public function testIsAuthorizationPayload(): void
    {
        $pvh = \Amtgard\IdP\Utility\Pvh::encode(
            1_700_000_000_000,
            \Amtgard\IdP\Utility\Pvh::policyHash('skbc', '[]', '')
        );

        $this->assertTrue(Jwt::isAuthorizationPayload(['pvh' => $pvh]));
        $this->assertTrue(Jwt::isAuthorizationPayload(['aud' => 'skbc', 'policy' => '[]']));
        $this->assertFalse(
            Jwt::isAuthorizationPayload(
                [
                'aud' => 'skbc',
                'sub' => 'user-1',
                'jti' => 'access-jti',
                'scopes' => ['profile', 'email'],
                ]
            )
        );
    }
}
