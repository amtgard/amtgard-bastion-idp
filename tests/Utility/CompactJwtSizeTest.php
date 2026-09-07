<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IdP\Models\AuthorizationJwtAssembler;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;

class CompactJwtSizeTest extends TestCase
{
    private const SUB = '550e8400-e29b-41d4-a716-446655440000';
    private const AUD = 'amtgard-ork';
    private const EXP = 1893456000;
    private const IAT = 1893452400;
    private const PVH = '018d0f0e0d0c0b0a0908070605040302010000abcdef';

    protected function setUp(): void
    {
        $devKeysDir = dirname(__DIR__, 2) . '/dev-keys';
        if (!file_exists('/tmp/private.key') && file_exists($devKeysDir . '/private.key')) {
            @copy($devKeysDir . '/private.key', '/tmp/private.key');
        }
        if (!file_exists('/tmp/public.key') && file_exists($devKeysDir . '/public.key')) {
            @copy($devKeysDir . '/public.key', '/tmp/public.key');
        }
        $_ENV['OAUTH_PRIVATE_KEY'] = '/tmp/private.key';
        $_ENV['OAUTH_PUBLIC_KEY'] = '/tmp/public.key';
    }

    public function testCompactRs256TokenByteLengthIsDocumented(): void
    {
        if (!file_exists('/tmp/private.key')) {
            $this->fail('dev-keys were not copied to /tmp for JWT signing');
        }

        $claims = AuthorizationJwtAssembler::compactClaims([
            'sub' => self::SUB,
            'aud' => self::AUD,
            'iss' => AuthorizationJwtAssembler::ISSUER,
            'exp' => self::EXP,
            'pvh' => self::PVH,
            'iat' => self::IAT,
        ]);

        $this->assertSame(['sub', 'aud', 'iss', 'exp', 'pvh', 'iat'], array_keys($claims));

        $privateKey = file_get_contents($_ENV['OAUTH_PRIVATE_KEY']);
        $token = JWT::encode($claims, $privateKey, 'RS256');
        $bytes = strlen($token);

        $golden = file_get_contents(
            dirname(__DIR__, 2) . '/agent/cursor/jwt-pvh-cache/goldens/compact-jwt-size.md'
        );
        $this->assertNotFalse($golden);
        $this->assertMatchesRegularExpression(
            '/\\*\\*' . $bytes . '\\*\\* byte/',
            $golden,
            "Golden must record measured compact RS256 strlen {$bytes}"
        );
    }
}
