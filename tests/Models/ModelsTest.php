<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Models;

use Amtgard\ActiveRecordOrm\Interface\EntityInterface;
use Amtgard\IdP\Models\AmtgardIdpJwt;
use Amtgard\IdP\Models\OAuthServerConfiguration;
use Amtgard\IdP\Models\Orn\IdpClaim;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Common\Repositories\JwtChallenge;
use Amtgard\IdP\Persistence\Server\Entities\Repository\UserJwtGeneration;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserJwtGenerationRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserLoginClientRepository;
use Amtgard\IdP\Persistence\Common\Repositories\UserPolicy;
use Amtgard\IdP\Utility\LoginSession;
use Amtgard\IdP\Utility\Pvh;
use Amtgard\IdP\Utility\PvhCacheRecord;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ModelsTest extends TestCase
{
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
        $_ENV['AUTH_SERVER_DEFUSE_KEY'] = str_repeat('a', 64);
        $_ENV['OAUTH_AUTH_TOKEN_TTL'] = 'PT10M';
        $_ENV['OAUTH_REFRESH_TOKEN_TTL'] = 'P1M';
        $_ENV['OAUTH_ACCESS_TOKEN_TTL'] = 'PT1H';
    }

    public function testAmtgardIdpJwt(): void
    {
        $userPolicy = $this->createMock(UserPolicy::class);
        $userPolicy->method('toPolicyJson')->willReturn('{"foo":"bar"}');

        $jwtChallenge = $this->createMock(JwtChallenge::class);
        $jwtChallenge->method('createChallenge')->willReturn('challenge123');

        $user = $this->createMock(EntityInterface::class);
        $user->userId = 'user-123';
        $user->id = 7;
        $user->orkUserId = 456;
        $user->username = 'testuser';
        $user->email = 'test@example.com';

        @session_start();
        $_SESSION['client_id'] = 'client-123';
        LoginSession::setLoginId(55);

        $clientRepository = $this->createMock(ClientRepository::class);
        $client = new class extends \Amtgard\IdP\Persistence\Server\Entities\Repository\Client {
            public function getId(): int
            {
                return 99;
            }
        };
        $clientRepository->method('findClientByIdentifier')->with('client-123')->willReturn($client);

        $metadataRepository = $this->createMock(UserLoginClientRepository::class);
        $metadataRepository->method('getMetadataForJwt')->with(55, 99)->willReturn(['tier' => 'gold']);

        $userLoginRepository = $this->createMock(UserLoginRepository::class);

        $expectedHash = Pvh::policyHash(
            'client-123',
            '{"foo":"bar"}',
            Pvh::canonicalMetadata(['tier' => 'gold'])
        );
        $expectedPvh = Pvh::encode(1_700_000_000_000, $expectedHash);
        $generation = $this->jwtGeneration([
            'userUuid' => 'user-123',
            'aud' => 'client-123',
            'pvh' => $expectedPvh,
            'prevPvh' => null,
        ]);

        $generationRepository = $this->createMock(UserJwtGenerationRepository::class);
        $generationRepository->expects($this->once())
            ->method('saveForPolicyHash')
            ->with(
                7,
                'user-123',
                99,
                'client-123',
                $expectedHash,
                $this->isType('int')
            )
            ->willReturn($generation);

        $redis = $this->createMock(RedisCacheRepository::class);
        $redis->expects($this->once())
            ->method('setPvhRecord')
            ->with($this->callback(function (PvhCacheRecord $record) use ($expectedPvh): bool {
                return $record->getUserUuid() === 'user-123'
                    && $record->getAud() === 'client-123'
                    && $record->getEmail() === 'test@example.com'
                    && $record->getPvh() === $expectedPvh
                    && $record->getPrevPvh() === null;
            }));

        $idpJwt = new AmtgardIdpJwt(
            $userPolicy,
            $jwtChallenge,
            $clientRepository,
            $metadataRepository,
            $userLoginRepository,
            $this->createMock(LoggerInterface::class),
            $generationRepository,
            $redis,
        );
        $tokens = $idpJwt->buildAuthorizationTokens($user);
        $jwtString = $tokens['jwt'];
        $compactString = $tokens['compact_jwt'];

        $this->assertIsString($jwtString);
        $this->assertIsString($compactString);
        $payload = json_decode(base64_decode(strtr(explode('.', $jwtString)[1], '-_', '+/')), true);
        $this->assertSame(['tier' => 'gold'], $payload['client_metadata']);
        $this->assertSame($expectedPvh, $payload['pvh']);

        $compactPayload = json_decode(base64_decode(strtr(explode('.', $compactString)[1], '-_', '+/')), true);
        $this->assertSame($payload['sub'], $compactPayload['sub']);
        $this->assertSame($payload['aud'], $compactPayload['aud']);
        $this->assertSame($payload['iss'], $compactPayload['iss']);
        $this->assertSame($payload['exp'], $compactPayload['exp']);
        $this->assertSame($payload['pvh'], $compactPayload['pvh']);
        $this->assertSame($payload['iat'], $compactPayload['iat']);
        $this->assertArrayNotHasKey('policy', $compactPayload);
        $this->assertArrayNotHasKey('email', $compactPayload);
        $this->assertArrayNotHasKey('orkid', $compactPayload);
        $this->assertArrayNotHasKey('challenge', $compactPayload);
        $this->assertArrayNotHasKey('client_metadata', $compactPayload);
        $this->assertSame(
            ['sub', 'aud', 'iss', 'exp', 'pvh', 'iat'],
            array_keys($compactPayload)
        );

        // test validate
        $jwtChallenge->expects($this->once())
            ->method('validateChallenge')
            ->with('some-jwt-string')
            ->willReturn(true);

        $this->assertTrue($idpJwt->validateJwtChallenge('some-jwt-string'));
    }

    public function testCompactClaimsOmitsFatOnlyFields(): void
    {
        $compact = \Amtgard\IdP\Models\AuthorizationJwtAssembler::compactClaims([
            'iat' => 1,
            'sub' => 'user-1',
            'iss' => 'https://idp.amtgard.com',
            'orkid' => 9,
            'email' => 'a@b.c',
            'policy' => '{}',
            'challenge' => 'x',
            'exp' => 2,
            'aud' => 'client-1',
            'client_metadata' => ['k' => 'v'],
            'pvh' => 'abc',
        ]);

        $this->assertSame([
            'sub' => 'user-1',
            'aud' => 'client-1',
            'iss' => 'https://idp.amtgard.com',
            'exp' => 2,
            'pvh' => 'abc',
            'iat' => 1,
        ], $compact);
    }

    public function testOAuthServerConfiguration(): void
    {
        $clientRepo = $this->createMock(ClientRepositoryInterface::class);
        $scopeRepo = $this->createMock(ScopeRepositoryInterface::class);
        $accessTokenRepo = $this->createMock(AccessTokenRepositoryInterface::class);
        $authCodeRepo = $this->createMock(AuthCodeRepositoryInterface::class);
        $refreshTokenRepo = $this->createMock(RefreshTokenRepositoryInterface::class);

        $config = OAuthServerConfiguration::builder()
            ->clientRepository($clientRepo)
            ->scopeRepository($scopeRepo)
            ->accessTokenRepository($accessTokenRepo)
            ->authCodeRepository($authCodeRepo)
            ->refreshTokenRepository($refreshTokenRepo)
            ->build();

        $server = $config->build();
        $this->assertInstanceOf(\League\OAuth2\Server\AuthorizationServer::class, $server);
    }

    public function testIdpClaim(): void
    {
        $claim = \Amtgard\IAM\ClaimFactory::createOrn("Idp:0::::IDP/EditClient");
        $this->assertInstanceOf(IdpClaim::class, $claim);
    }

    private function jwtGeneration(array $fields): UserJwtGeneration
    {
        $row = (new \ReflectionClass(UserJwtGeneration::class))->newInstanceWithoutConstructor();
        \Closure::bind(function () use ($fields): void {
            foreach ($fields as $name => $value) {
                $this->$name = $value;
            }
        }, $row, UserJwtGeneration::class)();

        return $row;
    }
}
