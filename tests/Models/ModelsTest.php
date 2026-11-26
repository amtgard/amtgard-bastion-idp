<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Models;

use Amtgard\ActiveRecordOrm\Interface\EntityInterface;
use Amtgard\IdP\Models\AmtgardIdpJwt;
use Amtgard\IdP\Models\AuthorizationJwtAssembler;
use Amtgard\IdP\Models\CompositeAuthorizationJwtValidator;
use Amtgard\IdP\Models\OAuthServerConfiguration;
use Amtgard\IdP\Models\Oidc\OidcAuthCodeGrant;
use Amtgard\IdP\Models\Oidc\OidcIdTokenResponse;
use OpenIDConnectServer\Repositories\IdentityProviderInterface;
use Amtgard\IdP\Models\Orn\IdpClaim;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Common\Repositories\JwtChallenge;
use Amtgard\IdP\Persistence\Server\Entities\Repository\UserJwtGeneration;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserJwtGenerationRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserLoginClientRepository;
use Amtgard\IdP\Persistence\Common\Repositories\UserPolicy;
use Amtgard\IdP\Tests\Support\OidcFixtureKeys;
use Amtgard\IdP\Utility\EcJwkProvider;
use Amtgard\IdP\Utility\LoginSession;
use Amtgard\IdP\Utility\Pvh;
use Amtgard\IdP\Utility\PvhCacheRecord;
use Amtgard\IdP\Utility\RsaJwkProvider;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Ecdsa\Sha256 as EcdsaSha256;
use Lcobucci\JWT\Signer\Rsa\Sha256 as RsaSha256;
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
        $setup = $this->mintSetup(new RsaSha256());

        $setup['generationRepository']->expects($this->once())
            ->method('saveForPolicyHash')
            ->with(
                7,
                'user-123',
                99,
                'client-123',
                $setup['expectedHash'],
                $this->isType('int')
            )
            ->willReturn($setup['generation']);

        $setup['redis']->expects($this->once())
            ->method('setPvhRecord')
            ->with($this->callback(function (PvhCacheRecord $record) use ($setup): bool {
                return $record->getUserUuid() === 'user-123'
                    && $record->getAud() === 'client-123'
                    && $record->getEmail() === 'test@example.com'
                    && $record->getPvh() === $setup['expectedPvh']
                    && $record->getPrevPvh() === null;
            }));

        $tokens = $setup['idpJwt']->buildAuthorizationTokens($setup['user']);
        $jwtString = $tokens['jwt'];
        $compactString = $tokens['compact_jwt'];

        $this->assertIsString($jwtString);
        $this->assertIsString($compactString);
        $payload = json_decode(base64_decode(strtr(explode('.', $jwtString)[1], '-_', '+/')), true);
        $this->assertSame(['tier' => 'gold'], $payload['client_metadata']);
        $this->assertSame($setup['expectedPvh'], $payload['pvh']);

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

        // M5: minted tokens carry the alg + kid of the signing key.
        $publicKey = file_get_contents('/tmp/public.key');
        $this->assertIsString($publicKey);
        $rsaKid = (new RsaJwkProvider($publicKey))->kid();
        $this->assertSame('RS256', $this->jwtHeader($jwtString)['alg']);
        $this->assertSame($rsaKid, $this->jwtHeader($jwtString)['kid']);
        $this->assertSame('RS256', $this->jwtHeader($compactString)['alg']);
        $this->assertSame($rsaKid, $this->jwtHeader($compactString)['kid']);

        // test validate
        $setup['jwtChallenge']->expects($this->once())
            ->method('validateChallenge')
            ->with('some-jwt-string')
            ->willReturn(true);

        $this->assertTrue($setup['idpJwt']->validateJwtChallenge('some-jwt-string'));
    }

    public function testAmtgardIdpJwtEs256(): void
    {
        $saved = $this->overrideOAuthEnv([
            'OAUTH_PRIVATE_KEY' => OidcFixtureKeys::privateEcKeyPath(),
            'OAUTH_PUBLIC_KEY' => OidcFixtureKeys::publicEcKeyPath(),
            'OAUTH_PUBLIC_KEY_PREVIOUS' => '/tmp/public.key',
        ]);

        try {
            $setup = $this->mintSetup(new EcdsaSha256());
            $setup['generationRepository']->method('saveForPolicyHash')->willReturn($setup['generation']);
            $tokens = $setup['idpJwt']->buildAuthorizationTokens($setup['user']);

            $ecKid = (new EcJwkProvider(OidcFixtureKeys::publicEcPem()))->kid();
            $this->assertSame('ES256', $this->jwtHeader($tokens['jwt'])['alg']);
            $this->assertSame($ecKid, $this->jwtHeader($tokens['jwt'])['kid']);
            $this->assertSame('ES256', $this->jwtHeader($tokens['compact_jwt'])['alg']);
            $this->assertSame($ecKid, $this->jwtHeader($tokens['compact_jwt'])['kid']);

            $decoded = JWT::decode($tokens['jwt'], new Key(OidcFixtureKeys::publicEcPem(), 'ES256'));
            $this->assertSame('user-123', $decoded->sub);
            $this->assertSame($setup['expectedPvh'], $decoded->pvh);

            $compactDecoded = JWT::decode($tokens['compact_jwt'], new Key(OidcFixtureKeys::publicEcPem(), 'ES256'));
            $this->assertSame('user-123', $compactDecoded->sub);
            $this->assertSame($setup['expectedPvh'], $compactDecoded->pvh);

            // Overlap: the ES256 mint validates through the composite built
            // from the environment (EC current + RSA previous).
            $validator = CompositeAuthorizationJwtValidator::fromEnvironment();
            $this->assertSame($tokens['jwt'], $validator->validateJwtSignature($tokens['jwt']));
            $this->assertSame($tokens['compact_jwt'], $validator->validateJwtSignature($tokens['compact_jwt']));
        } finally {
            $this->restoreOAuthEnv($saved);
        }
    }

    public function testAmtgardIdpJwtWithoutSignerThrows(): void
    {
        $setup = $this->mintSetup(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Authorization JWT signer was not injected.');

        $setup['idpJwt']->buildAuthorizationTokens($setup['user']);
    }

    /**
     * Shared mint fixture: the real assembler with stubbed repositories,
     * mirroring the pre-M5 testAmtgardIdpJwt setup. The signer is injected
     * (or omitted, for the fail-fast test); strict mock expectations are
     * set by the caller, after this returns and before minting.
     *
     * @return array{idpJwt: AmtgardIdpJwt, user: EntityInterface, jwtChallenge: JwtChallenge,
     *     expectedPvh: string, expectedHash: string, generation: UserJwtGeneration,
     *     generationRepository: UserJwtGenerationRepository, redis: RedisCacheRepository}
     */
    private function mintSetup(?Signer $signer): array
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

        $redis = $this->createMock(RedisCacheRepository::class);

        $assembler = new AuthorizationJwtAssembler(
            $userPolicy,
            $jwtChallenge,
            $clientRepository,
            $metadataRepository,
            $userLoginRepository,
            \Amtgard\IdP\Tests\Utility\Log\IdpLoggingTestFactory::create(),
            $generationRepository,
        );
        $builder = AmtgardIdpJwt::builder()
            ->assembler($assembler)
            ->redisCacheRepository($redis)
            ->idpLogging(\Amtgard\IdP\Tests\Utility\Log\IdpLoggingTestFactory::create());
        if ($signer !== null) {
            $builder->signer($signer);
        }

        return [
            'idpJwt' => $builder->build(),
            'user' => $user,
            'jwtChallenge' => $jwtChallenge,
            'expectedPvh' => $expectedPvh,
            'expectedHash' => $expectedHash,
            'generation' => $generation,
            'generationRepository' => $generationRepository,
            'redis' => $redis,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jwtHeader(string $jwt): array
    {
        $header = json_decode(base64_decode(strtr(explode('.', $jwt)[0], '-_', '+/')), true);
        $this->assertIsArray($header);

        return $header;
    }

    /**
     * @param array<string, string|null> $overrides
     * @return array<string, array{env: mixed, putenv: mixed}>
     */
    private function overrideOAuthEnv(array $overrides): array
    {
        $saved = [];
        foreach ($overrides as $key => $value) {
            $saved[$key] = ['env' => $_ENV[$key] ?? null, 'putenv' => getenv($key)];
            if ($value === null) {
                unset($_ENV[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                putenv($key . '=' . $value);
            }
        }

        return $saved;
    }

    /** @param array<string, array{env: mixed, putenv: mixed}> $saved */
    private function restoreOAuthEnv(array $saved): void
    {
        foreach ($saved as $key => $previous) {
            if ($previous['env'] === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $previous['env'];
            }
            if ($previous['putenv'] === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $previous['putenv']);
            }
        }
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
            ->identityProvider($this->createStub(IdentityProviderInterface::class))
            ->build();

        $server = $config->build();
        $this->assertInstanceOf(\League\OAuth2\Server\AuthorizationServer::class, $server);

        $responseType = (new \ReflectionProperty($server, 'responseType'))->getValue($server);
        $this->assertInstanceOf(OidcIdTokenResponse::class, $responseType);
        $this->assertNotNull((new \ReflectionProperty($responseType, 'keyIdentifier'))->getValue($responseType));

        $grants = (new \ReflectionProperty($server, 'enabledGrantTypes'))->getValue($server);
        $this->assertInstanceOf(OidcAuthCodeGrant::class, $grants['authorization_code']);
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
