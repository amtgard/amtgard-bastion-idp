<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Models\Oidc;

use Amtgard\IdP\Models\Oidc\CompositeBearerTokenValidator;
use Amtgard\IdP\Models\Oidc\Es256BearerTokenValidator;
use Amtgard\IdP\Models\Oidc\Rs256BearerTokenValidator;
use Amtgard\IdP\Tests\Support\FirebaseJwtTestFactory;
use Amtgard\IdP\Tests\Support\OidcFixtureKeys;
use Amtgard\IdP\Utility\JwksFactory;
use Amtgard\IdP\Utility\OAuthKeyMaterial;
use Firebase\JWT\JWT as FirebaseJwt;
use League\OAuth2\Server\AuthorizationValidators\AuthorizationValidatorInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Bearer-token validators (ES256 design §5.3, §8 M1): each implementation
 * accepts only its own algorithm's tokens; the composite tries each bound
 * implementation in order and surfaces the first failure.
 */
class BearerTokenValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FirebaseJwtTestFactory::ensureKeys();
    }

    public function testRs256ValidatorAcceptsRs256TokenAndSetsAttributes(): void
    {
        $validator = new Rs256BearerTokenValidator(
            $this->repository(),
            OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY')
        );

        $validated = $validator->validateAuthorization(
            $this->bearerRequest(FirebaseJwtTestFactory::oauthAccessTokenForUserAndClient('user-1', 'client-1'))
        );

        $this->assertSame('jti-access-token', $validated->getAttribute('oauth_access_token_id'));
        $this->assertSame('client-1', $validated->getAttribute('oauth_client_id'));
        $this->assertSame('user-1', $validated->getAttribute('oauth_user_id'));
        $this->assertSame(['profile', 'email'], $validated->getAttribute('oauth_scopes'));
    }

    public function testEs256ValidatorAcceptsEs256TokenAndSetsAttributes(): void
    {
        $validator = new Es256BearerTokenValidator(
            $this->repository(),
            OidcFixtureKeys::publicEcPem()
        );

        $validated = $validator->validateAuthorization($this->bearerRequest($this->es256AccessToken()));

        $this->assertSame('jti-access-token', $validated->getAttribute('oauth_access_token_id'));
        $this->assertSame('client-1', $validated->getAttribute('oauth_client_id'));
        $this->assertSame('user-1', $validated->getAttribute('oauth_user_id'));
        $this->assertSame(['profile', 'email'], $validated->getAttribute('oauth_scopes'));
    }

    public function testRs256ValidatorRejectsEs256Token(): void
    {
        $validator = new Rs256BearerTokenValidator(
            $this->repository(),
            OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY')
        );

        $this->assertAccessDenied(
            fn () => $validator->validateAuthorization($this->bearerRequest($this->es256AccessToken())),
            'Access token could not be verified'
        );
    }

    public function testEs256ValidatorRejectsRs256Token(): void
    {
        $validator = new Es256BearerTokenValidator(
            $this->repository(),
            OidcFixtureKeys::publicEcPem()
        );

        $this->assertAccessDenied(
            fn () => $validator->validateAuthorization(
                $this->bearerRequest(FirebaseJwtTestFactory::oauthAccessTokenForUserAndClient('user-1', 'client-1'))
            ),
            'Access token could not be verified'
        );
    }

    public function testValidatorsRejectMalformedToken(): void
    {
        $this->assertAccessDenied(
            fn () => (new Rs256BearerTokenValidator(
                $this->repository(),
                OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY')
            ))->validateAuthorization($this->bearerRequest('not-a-jwt'))
        );
        $this->assertAccessDenied(
            fn () => (new Es256BearerTokenValidator(
                $this->repository(),
                OidcFixtureKeys::publicEcPem()
            ))->validateAuthorization($this->bearerRequest('not-a-jwt'))
        );
    }

    public function testValidatorsRejectNoneToken(): void
    {
        $none = $this->noneToken();

        $this->assertAccessDenied(
            fn () => (new Rs256BearerTokenValidator(
                $this->repository(),
                OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY')
            ))->validateAuthorization($this->bearerRequest($none))
        );
        $this->assertAccessDenied(
            fn () => (new Es256BearerTokenValidator(
                $this->repository(),
                OidcFixtureKeys::publicEcPem()
            ))->validateAuthorization($this->bearerRequest($none))
        );
    }

    public function testValidatorsRejectUnknownKeyToken(): void
    {
        $unknownRs256 = $this->unknownKeyRs256Token();
        $unknownEs256 = $this->unknownKeyEs256Token();

        $this->assertAccessDenied(
            fn () => (new Rs256BearerTokenValidator(
                $this->repository(),
                OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY')
            ))->validateAuthorization($this->bearerRequest($unknownRs256)),
            'Access token could not be verified'
        );
        $this->assertAccessDenied(
            fn () => (new Es256BearerTokenValidator(
                $this->repository(),
                OidcFixtureKeys::publicEcPem()
            ))->validateAuthorization($this->bearerRequest($unknownEs256)),
            'Access token could not be verified'
        );
    }

    public function testValidatorsRejectExpiredToken(): void
    {
        $expiredRs256 = FirebaseJwtTestFactory::oauthAccessTokenForUserAndClient('user-1', 'client-1', -60);
        $expiredEs256 = $this->es256AccessToken(-60);

        $this->assertAccessDenied(
            fn () => (new Rs256BearerTokenValidator(
                $this->repository(),
                OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY')
            ))->validateAuthorization($this->bearerRequest($expiredRs256)),
            'Access token could not be verified'
        );
        $this->assertAccessDenied(
            fn () => (new Es256BearerTokenValidator(
                $this->repository(),
                OidcFixtureKeys::publicEcPem()
            ))->validateAuthorization($this->bearerRequest($expiredEs256)),
            'Access token could not be verified'
        );
    }

    public function testValidatorsRejectRevokedToken(): void
    {
        $this->assertAccessDenied(
            fn () => (new Rs256BearerTokenValidator(
                $this->repository(true),
                OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY')
            ))->validateAuthorization(
                $this->bearerRequest(FirebaseJwtTestFactory::oauthAccessTokenForUserAndClient('user-1', 'client-1'))
            ),
            'Access token has been revoked'
        );
        $this->assertAccessDenied(
            fn () => (new Es256BearerTokenValidator(
                $this->repository(true),
                OidcFixtureKeys::publicEcPem()
            ))->validateAuthorization($this->bearerRequest($this->es256AccessToken())),
            'Access token has been revoked'
        );
    }

    public function testValidatorsRejectMissingAuthorizationHeader(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/resources/userinfo');

        $this->assertAccessDenied(
            fn () => (new Rs256BearerTokenValidator(
                $this->repository(),
                OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY')
            ))->validateAuthorization($request),
            'Missing "Authorization" header'
        );
        $this->assertAccessDenied(
            fn () => (new Es256BearerTokenValidator(
                $this->repository(),
                OidcFixtureKeys::publicEcPem()
            ))->validateAuthorization($request),
            'Missing "Authorization" header'
        );
    }

    public function testRs256ValidatorLogsValidatedTokenAlg(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);
        $validator = new Rs256BearerTokenValidator(
            $this->repository(),
            OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY'),
            $logger
        );

        $validator->validateAuthorization(
            $this->bearerRequest(FirebaseJwtTestFactory::oauthAccessTokenForUserAndClient('user-1', 'client-1'))
        );

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame(Level::Info, $records[0]->level);
        $this->assertSame('bearer token validated', $records[0]->message);
        $this->assertSame('RS256', $records[0]->context['alg']);
    }

    public function testValidatorsTrimRawBearerHeaderValue(): void
    {
        $rs256 = FirebaseJwtTestFactory::oauthAccessTokenForUserAndClient('user-1', 'client-1');
        $es256 = $this->es256AccessToken();

        $validatedRs256 = (new Rs256BearerTokenValidator(
            $this->repository(),
            OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY')
        ))->validateAuthorization($this->rawBearerRequest($rs256 . ' '));
        $this->assertInstanceOf(ServerRequestInterface::class, $validatedRs256);

        $validatedEs256 = (new Es256BearerTokenValidator(
            $this->repository(),
            OidcFixtureKeys::publicEcPem()
        ))->validateAuthorization($this->rawBearerRequest($es256 . ' '));
        $this->assertInstanceOf(ServerRequestInterface::class, $validatedEs256);
    }

    public function testValidatorKeepsMultiValueAudAsArray(): void
    {
        $now = time();
        $rs256 = FirebaseJwtTestFactory::encode([
            'aud' => ['client-1', 'client-2'],
            'jti' => 'jti-access-token',
            'sub' => 'user-1',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 3600,
            'scopes' => ['profile', 'email'],
        ]);
        $es256 = FirebaseJwtTestFactory::encodeEs256([
            'aud' => ['client-1', 'client-2'],
            'jti' => 'jti-access-token',
            'sub' => 'user-1',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 3600,
            'scopes' => ['profile', 'email'],
        ]);

        $validatedRs256 = (new Rs256BearerTokenValidator(
            $this->repository(),
            OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY')
        ))->validateAuthorization($this->bearerRequest($rs256));
        $this->assertSame(['client-1', 'client-2'], $validatedRs256->getAttribute('oauth_client_id'));

        $validatedEs256 = (new Es256BearerTokenValidator(
            $this->repository(),
            OidcFixtureKeys::publicEcPem()
        ))->validateAuthorization($this->bearerRequest($es256));
        $this->assertSame(['client-1', 'client-2'], $validatedEs256->getAttribute('oauth_client_id'));
    }

    public function testWinningValidatorLogsValidatedTokenAlg(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);
        $validator = new Es256BearerTokenValidator(
            $this->repository(),
            OidcFixtureKeys::publicEcPem(),
            $logger
        );

        $validator->validateAuthorization($this->bearerRequest($this->es256AccessToken()));

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame(Level::Info, $records[0]->level);
        $this->assertSame('bearer token validated', $records[0]->message);
        $this->assertSame('ES256', $records[0]->context['alg']);
    }

    public function testCompositeAcceptsEs256AndRs256Tokens(): void
    {
        $composite = $this->composite();

        $es256 = $composite->validateAuthorization($this->bearerRequest($this->es256AccessToken()));
        $this->assertSame('user-1', $es256->getAttribute('oauth_user_id'));

        $rs256 = $composite->validateAuthorization(
            $this->bearerRequest(FirebaseJwtTestFactory::oauthAccessTokenForUserAndClient('user-1', 'client-1'))
        );
        $this->assertSame('user-1', $rs256->getAttribute('oauth_user_id'));
    }

    public function testCompositeRejectsNoneUnknownAlgAndUnknownKeyTokens(): void
    {
        $composite = $this->composite();

        $this->assertAccessDenied(
            fn () => $composite->validateAuthorization($this->bearerRequest($this->noneToken())),
        );
        $this->assertAccessDenied(
            fn () => $composite->validateAuthorization($this->bearerRequest($this->hs256Token())),
            'Access token could not be verified'
        );
        $this->assertAccessDenied(
            fn () => $composite->validateAuthorization($this->bearerRequest($this->unknownKeyRs256Token())),
            'Access token could not be verified'
        );
        $this->assertAccessDenied(
            fn () => $composite->validateAuthorization($this->bearerRequest($this->unknownKeyEs256Token())),
            'Access token could not be verified'
        );
    }

    public function testCompositeSurfacesFirstFailure(): void
    {
        // A revoked RS256 token: the ES256 implementation (first) rejects it
        // at the signature constraint, so its failure is the one surfaced,
        // per design §5.3.
        $composite = $this->compositeWithRevokedRs256();

        $this->assertAccessDenied(
            fn () => $composite->validateAuthorization(
                $this->bearerRequest(FirebaseJwtTestFactory::oauthAccessTokenForUserAndClient('user-1', 'client-1'))
            ),
            'Access token could not be verified'
        );
    }

    public function testCompositeSurfacesFirstFailureForRevokedEs256Token(): void
    {
        // A revoked ES256 token: the ES256 implementation (first) rejects it
        // as revoked, so its failure — not the generic fallback — is the
        // one surfaced, per design §5.3.
        $revoked = $this->createStub(AccessTokenRepositoryInterface::class);
        $revoked->method('isAccessTokenRevoked')->willReturn(true);
        $composite = new CompositeBearerTokenValidator(
            new Es256BearerTokenValidator($revoked, OidcFixtureKeys::publicEcPem()),
            new Rs256BearerTokenValidator($this->repository(), OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY')),
        );

        $this->assertAccessDenied(
            fn () => $composite->validateAuthorization($this->bearerRequest($this->es256AccessToken())),
            'Access token has been revoked'
        );
    }

    public function testCompositeSetsRequestAttributes(): void
    {
        $composite = $this->composite();

        $es256 = $composite->validateAuthorization($this->bearerRequest($this->es256AccessToken()));
        $this->assertSame('jti-access-token', $es256->getAttribute('oauth_access_token_id'));
        $this->assertSame('client-1', $es256->getAttribute('oauth_client_id'));
        $this->assertSame('user-1', $es256->getAttribute('oauth_user_id'));
        $this->assertSame(['profile', 'email'], $es256->getAttribute('oauth_scopes'));

        $rs256 = $composite->validateAuthorization(
            $this->bearerRequest(FirebaseJwtTestFactory::oauthAccessTokenForUserAndClient('user-1', 'client-1'))
        );
        $this->assertSame('jti-access-token', $rs256->getAttribute('oauth_access_token_id'));
        $this->assertSame('client-1', $rs256->getAttribute('oauth_client_id'));
        $this->assertSame('user-1', $rs256->getAttribute('oauth_user_id'));
        $this->assertSame(['profile', 'email'], $rs256->getAttribute('oauth_scopes'));
    }

    public function testCompositeRejectsExpiredAndRevokedTokens(): void
    {
        $composite = $this->composite();

        $this->assertAccessDenied(
            fn () => $composite->validateAuthorization($this->bearerRequest($this->es256AccessToken(-60))),
            'Access token could not be verified'
        );
        $this->assertAccessDenied(
            fn () => $composite->validateAuthorization(
                $this->bearerRequest(
                    FirebaseJwtTestFactory::oauthAccessTokenForUserAndClient('user-1', 'client-1', -60)
                )
            ),
            'Access token could not be verified'
        );

        // A revoked ES256 token: the ES256 implementation (first) rejects it
        // as revoked, so that failure is the one surfaced.
        $revoked = $this->compositeWithRepository($this->repository(true));
        $this->assertAccessDenied(
            fn () => $revoked->validateAuthorization($this->bearerRequest($this->es256AccessToken())),
            'Access token has been revoked'
        );
        // A revoked RS256 token: the ES256 implementation (first) rejects it
        // at the signature constraint, so the generic failure is surfaced.
        $this->assertAccessDenied(
            fn () => $revoked->validateAuthorization(
                $this->bearerRequest(FirebaseJwtTestFactory::oauthAccessTokenForUserAndClient('user-1', 'client-1'))
            ),
            'Access token could not be verified'
        );
    }

    public function testValidatorsRejectUnknownKidToken(): void
    {
        $unknownKidEs256 = $this->es256AccessTokenWithKid('unknown-kid');
        $unknownKidRs256 = $this->rs256AccessTokenWithKid('unknown-kid');

        $this->assertAccessDenied(
            fn () => (new Es256BearerTokenValidator(
                $this->repository(),
                OidcFixtureKeys::publicEcPem()
            ))->validateAuthorization($this->bearerRequest($unknownKidEs256)),
            'Access token could not be verified'
        );
        $this->assertAccessDenied(
            fn () => (new Rs256BearerTokenValidator(
                $this->repository(),
                OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY')
            ))->validateAuthorization($this->bearerRequest($unknownKidRs256)),
            'Access token could not be verified'
        );
    }

    public function testValidatorsAcceptKnownKidToken(): void
    {
        $ecKid = JwksFactory::fromPublicKeyPem(OidcFixtureKeys::publicEcPem())->kid();
        $validatedEs256 = (new Es256BearerTokenValidator(
            $this->repository(),
            OidcFixtureKeys::publicEcPem()
        ))->validateAuthorization($this->bearerRequest($this->es256AccessTokenWithKid($ecKid)));
        $this->assertSame('user-1', $validatedEs256->getAttribute('oauth_user_id'));

        $rsaKid = JwksFactory::fromPublicKeyPem(
            OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY')
        )->kid();
        $validatedRs256 = (new Rs256BearerTokenValidator(
            $this->repository(),
            OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY')
        ))->validateAuthorization($this->bearerRequest($this->rs256AccessTokenWithKid($rsaKid)));
        $this->assertSame('user-1', $validatedRs256->getAttribute('oauth_user_id'));
    }

    public function testCompositeRejectsUnknownKidToken(): void
    {
        $composite = $this->composite();

        $this->assertAccessDenied(
            fn () => $composite->validateAuthorization(
                $this->bearerRequest($this->es256AccessTokenWithKid('unknown-kid'))
            ),
            'Access token could not be verified'
        );
        $this->assertAccessDenied(
            fn () => $composite->validateAuthorization(
                $this->bearerRequest($this->rs256AccessTokenWithKid('unknown-kid'))
            ),
            'Access token could not be verified'
        );
    }

    public function testFromEnvironmentBuildsOverlapComposite(): void
    {
        FirebaseJwtTestFactory::ensureKeys();
        $saved = $this->overrideOAuthEnv([
            'OAUTH_PUBLIC_KEY' => OidcFixtureKeys::publicEcKeyPath(),
            'OAUTH_PUBLIC_KEY_PREVIOUS' => OidcFixtureKeys::publicKeyPath(),
        ]);

        try {
            $handler = new TestHandler();
            $logger = new Logger('test');
            $logger->pushHandler($handler);
            $composite = CompositeBearerTokenValidator::fromEnvironment($this->repository(), $logger);

            $es256 = $composite->validateAuthorization($this->bearerRequest($this->es256AccessToken()));
            $this->assertSame('user-1', $es256->getAttribute('oauth_user_id'));

            $rs256 = $composite->validateAuthorization($this->bearerRequest($this->rs256FixtureAccessToken()));
            $this->assertSame('user-1', $rs256->getAttribute('oauth_user_id'));

            $records = $handler->getRecords();
            $this->assertCount(2, $records);
            $this->assertSame('ES256', $records[0]->context['alg']);
            $this->assertSame('RS256', $records[1]->context['alg']);
        } finally {
            $this->restoreOAuthEnv($saved);
        }
    }

    public function testFromEnvironmentWithoutPreviousKeyIsSingleKey(): void
    {
        FirebaseJwtTestFactory::ensureKeys();
        $saved = $this->overrideOAuthEnv([
            'OAUTH_PUBLIC_KEY' => OidcFixtureKeys::publicEcKeyPath(),
            'OAUTH_PUBLIC_KEY_PREVIOUS' => null,
        ]);

        try {
            $composite = CompositeBearerTokenValidator::fromEnvironment($this->repository());

            $es256 = $composite->validateAuthorization($this->bearerRequest($this->es256AccessToken()));
            $this->assertSame('user-1', $es256->getAttribute('oauth_user_id'));

            $this->assertAccessDenied(
                fn () => $composite->validateAuthorization($this->bearerRequest($this->rs256FixtureAccessToken())),
                'Access token could not be verified'
            );
        } finally {
            $this->restoreOAuthEnv($saved);
        }
    }

    public function testFromEnvironmentReadsPreviousKeyFromServerEnvWhenPutenvIsUnset(): void
    {
        // $_ENV wins over getenv(): the previous key must still be picked
        // up when it is set in $_ENV only (putenv cleared). Kills the
        // coalesce mutant that swaps the ?? operands.
        FirebaseJwtTestFactory::ensureKeys();
        $saved = $this->overrideOAuthEnv([
            'OAUTH_PUBLIC_KEY' => OidcFixtureKeys::publicEcKeyPath(),
            'OAUTH_PUBLIC_KEY_PREVIOUS' => OidcFixtureKeys::publicKeyPath(),
        ]);
        putenv('OAUTH_PUBLIC_KEY_PREVIOUS');

        try {
            $composite = CompositeBearerTokenValidator::fromEnvironment($this->repository());

            $rs256 = $composite->validateAuthorization($this->bearerRequest($this->rs256FixtureAccessToken()));
            $this->assertSame('user-1', $rs256->getAttribute('oauth_user_id'));
        } finally {
            $this->restoreOAuthEnv($saved);
        }
    }

    public function testValidatorFromPublicKeyPemRejectsUnsupportedKeys(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to parse public key for bearer token validation.');

        CompositeBearerTokenValidator::validatorFromPublicKeyPem('not-a-key', $this->repository());
    }

    public function testValidatorFromPublicKeyPemRejectsNonP256Curve(): void
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'secp384r1',
        ]);
        $this->assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        $publicPem = (string) ($details['key'] ?? '');
        $this->assertNotSame('', $publicPem);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Bearer token signing key must be an RSA or P-256 EC public key.');

        CompositeBearerTokenValidator::validatorFromPublicKeyPem($publicPem, $this->repository());
    }

    public function testCompositeTriesValidatorsInOrder(): void
    {
        $request = $this->bearerRequest($this->es256AccessToken());

        $first = $this->createMock(AuthorizationValidatorInterface::class);
        $first->expects($this->once())
            ->method('validateAuthorization')
            ->willReturn($request);
        $second = $this->createMock(AuthorizationValidatorInterface::class);
        $second->expects($this->never())
            ->method('validateAuthorization');

        $composite = new CompositeBearerTokenValidator($first, $second);

        $this->assertSame($request, $composite->validateAuthorization($request));
    }

    public function testCompositeFallsThroughToSecondValidator(): void
    {
        $request = $this->bearerRequest($this->es256AccessToken());

        $first = $this->createMock(AuthorizationValidatorInterface::class);
        $first->expects($this->once())
            ->method('validateAuthorization')
            ->willThrowException(OAuthServerException::accessDenied('first failure'));
        $second = $this->createMock(AuthorizationValidatorInterface::class);
        $second->expects($this->once())
            ->method('validateAuthorization')
            ->willReturn($request);

        $composite = new CompositeBearerTokenValidator($first, $second);

        $this->assertSame($request, $composite->validateAuthorization($request));
    }

    public function testCompositeWithNoValidatorsRejects(): void
    {
        $composite = new CompositeBearerTokenValidator();

        $this->assertAccessDenied(
            fn () => $composite->validateAuthorization($this->bearerRequest($this->es256AccessToken())),
            'Access token could not be verified'
        );
    }

    private function composite(): CompositeBearerTokenValidator
    {
        return new CompositeBearerTokenValidator(
            new Es256BearerTokenValidator($this->repository(), OidcFixtureKeys::publicEcPem()),
            new Rs256BearerTokenValidator($this->repository(), OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY')),
        );
    }

    private function compositeWithRevokedRs256(): CompositeBearerTokenValidator
    {
        $revoked = $this->createStub(AccessTokenRepositoryInterface::class);
        $revoked->method('isAccessTokenRevoked')->willReturn(true);

        return new CompositeBearerTokenValidator(
            new Es256BearerTokenValidator($this->repository(), OidcFixtureKeys::publicEcPem()),
            new Rs256BearerTokenValidator($revoked, OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY')),
        );
    }

    private function compositeWithRepository(AccessTokenRepositoryInterface $repository): CompositeBearerTokenValidator
    {
        return new CompositeBearerTokenValidator(
            new Es256BearerTokenValidator($repository, OidcFixtureKeys::publicEcPem()),
            new Rs256BearerTokenValidator($repository, OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY')),
        );
    }

    private function repository(bool $revoked = false): AccessTokenRepositoryInterface
    {
        $repository = $this->createStub(AccessTokenRepositoryInterface::class);
        $repository->method('isAccessTokenRevoked')->willReturn($revoked);

        return $repository;
    }

    private function bearerRequest(string $token): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/resources/userinfo')
            ->withHeader('Authorization', 'Bearer ' . $token);
    }

    /**
     * A request returning the raw Authorization value. Slim trims header
     * values on set, so a Slim-built request can never exercise the
     * validator's own trim(); this stub can.
     */
    private function rawBearerRequest(string $token): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('hasHeader')->willReturn(true);
        $request->method('getHeader')->willReturn(['Bearer ' . $token]);
        $request->method('withAttribute')->willReturn($request);

        return $request;
    }

    private function es256AccessToken(int $ttlSeconds = 3600): string
    {
        $now = time();

        return FirebaseJwtTestFactory::encodeEs256([
            'aud' => 'client-1',
            'jti' => 'jti-access-token',
            'sub' => 'user-1',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttlSeconds,
            'scopes' => ['profile', 'email'],
        ]);
    }

    private function es256AccessTokenWithKid(string $kid, int $ttlSeconds = 3600): string
    {
        $now = time();

        return FirebaseJwt::encode([
            'aud' => 'client-1',
            'jti' => 'jti-access-token',
            'sub' => 'user-1',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttlSeconds,
            'scopes' => ['profile', 'email'],
        ], OidcFixtureKeys::privateEcPem(), 'ES256', $kid);
    }

    private function rs256AccessTokenWithKid(string $kid, int $ttlSeconds = 3600): string
    {
        $now = time();
        $privateKey = file_get_contents(FirebaseJwtTestFactory::DEFAULT_PRIVATE_KEY_PATH);
        $this->assertNotFalse($privateKey);

        return FirebaseJwt::encode([
            'aud' => 'client-1',
            'jti' => 'jti-access-token',
            'sub' => 'user-1',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttlSeconds,
            'scopes' => ['profile', 'email'],
        ], $privateKey, 'RS256', $kid);
    }

    /**
     * RS256 access token signed with the OIDC RSA fixture key (not the
     * dev-keys pair): validates against OidcFixtureKeys::publicPem().
     */
    private function rs256FixtureAccessToken(int $ttlSeconds = 3600): string
    {
        $now = time();

        return FirebaseJwt::encode([
            'aud' => 'client-1',
            'jti' => 'jti-access-token',
            'sub' => 'user-1',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttlSeconds,
            'scopes' => ['profile', 'email'],
        ], OidcFixtureKeys::privatePem(), 'RS256');
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

    private function unknownKeyRs256Token(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key);
        $pem = '';
        $this->assertTrue(openssl_pkey_export($key, $pem));
        $now = time();

        return FirebaseJwt::encode([
            'aud' => 'client-1',
            'jti' => 'jti-access-token',
            'sub' => 'user-1',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 3600,
            'scopes' => ['profile', 'email'],
        ], $pem, 'RS256');
    }

    private function unknownKeyEs256Token(): string
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $this->assertNotFalse($key);
        $pem = '';
        $this->assertTrue(openssl_pkey_export($key, $pem));
        $now = time();

        return FirebaseJwt::encode([
            'aud' => 'client-1',
            'jti' => 'jti-access-token',
            'sub' => 'user-1',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 3600,
            'scopes' => ['profile', 'email'],
        ], $pem, 'ES256');
    }

    private function noneToken(): string
    {
        $now = time();

        return $this->base64Url((string) json_encode(['alg' => 'none', 'typ' => 'JWT']))
            . '.' . $this->base64Url((string) json_encode([
                'aud' => 'client-1',
                'jti' => 'jti-access-token',
                'sub' => 'user-1',
                'iat' => $now,
                'nbf' => $now,
                'exp' => $now + 3600,
                'scopes' => ['profile', 'email'],
            ]))
            . '.';
    }

    private function hs256Token(): string
    {
        $now = time();

        return FirebaseJwt::encode([
            'aud' => 'client-1',
            'jti' => 'jti-access-token',
            'sub' => 'user-1',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 3600,
            'scopes' => ['profile', 'email'],
        ], '0123456789abcdef0123456789abcdef', 'HS256');
    }

    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @param callable(): mixed $validate
     */
    private function assertAccessDenied(callable $validate, ?string $hint = null): void
    {
        try {
            $validate();
        } catch (OAuthServerException $exception) {
            $payload = $exception->getPayload();
            $this->assertSame('access_denied', $payload['error']);
            if ($hint !== null) {
                $this->assertSame($hint, $payload['hint'] ?? null);
            }

            return;
        }

        $this->fail('Expected OAuthServerException (access denied) to be thrown.');
    }
}
