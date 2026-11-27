<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IdP\Models\AuthorizationJwtAssembler;
use Amtgard\IdP\Tests\Support\OidcFixtureKeys;
use Amtgard\IdP\Utility\EcJwkProvider;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;

class CompactJwtSizeTest extends TestCase
{
    private const SUB = '550e8400-e29b-41d4-a716-446655440000';
    private const AUD = 'amtgard-ork';
    private const EXP = 1893456000;
    private const IAT = 1893452400;
    private const PVH = '018d0f0e0d0c0b0a0908070605040302010000abcdef';

    public function testCompactEs256TokenByteLengthIsDocumented(): void
    {
        $claims = AuthorizationJwtAssembler::compactClaims([
            'sub' => self::SUB,
            'aud' => self::AUD,
            'iss' => AuthorizationJwtAssembler::ISSUER,
            'exp' => self::EXP,
            'pvh' => self::PVH,
            'iat' => self::IAT,
        ]);

        $this->assertSame(['sub', 'aud', 'iss', 'exp', 'pvh', 'iat'], array_keys($claims));

        // M5: the compact token is minted ES256 with the current key's kid
        // (same path as AmtgardIdpJwt::buildAuthorizationTokens). The
        // signature is 64 bytes vs RSA-2048's 256, so the token shrinks
        // even with the kid header added.
        $kid = (new EcJwkProvider(OidcFixtureKeys::publicEcPem()))->kid();
        $token = JWT::encode($claims, OidcFixtureKeys::privateEcPem(), 'ES256', $kid);
        $bytes = strlen($token);

        $golden = file_get_contents(
            dirname(__DIR__, 2) . '/agent/cursor/jwt-pvh-cache/goldens/compact-jwt-size.md'
        );
        $this->assertNotFalse($golden);
        $this->assertMatchesRegularExpression(
            '/\\*\\*' . $bytes . '\\*\\* byte/',
            $golden,
            "Golden must record measured compact ES256 strlen {$bytes}"
        );
    }
}
