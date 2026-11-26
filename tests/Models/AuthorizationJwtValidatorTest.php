<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Models;

use Amtgard\IdP\Models\AuthorizationJwtValidator;
use Amtgard\IdP\Models\CompositeAuthorizationJwtValidator;
use Amtgard\IdP\Models\Es256AuthorizationJwtValidator;
use Amtgard\IdP\Models\Rs256AuthorizationJwtValidator;
use Amtgard\IdP\Tests\Support\FirebaseJwtTestFactory;
use Amtgard\IdP\Tests\Support\OidcFixtureKeys;
use Amtgard\IdP\Utility\OAuthKeyMaterial;
use Firebase\JWT\JWT as FirebaseJwt;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Authorization-JWT validators (ES256 design §5.4, §8 M1): each
 * implementation accepts only its own algorithm's tokens; the composite
 * tries each bound implementation in turn, ES256 first.
 */
class AuthorizationJwtValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FirebaseJwtTestFactory::ensureKeys();
    }

    public function testRs256ValidatorAcceptsRs256Token(): void
    {
        $validator = new Rs256AuthorizationJwtValidator(OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY'));
        $token = FirebaseJwtTestFactory::authorizationForUserAndClient('user-1', 'client-1');

        $this->assertSame($token, $validator->validateJwtSignature($token));
    }

    public function testEs256ValidatorAcceptsEs256Token(): void
    {
        $validator = new Es256AuthorizationJwtValidator(OidcFixtureKeys::publicEcPem());
        $token = FirebaseJwtTestFactory::encodeEs256([
            'iss' => 'http://localhost',
            'aud' => 'client-1',
            'sub' => 'user-1',
            'exp' => time() + 3600,
        ]);

        $this->assertSame($token, $validator->validateJwtSignature($token));
    }

    public function testRs256ValidatorRejectsEs256Token(): void
    {
        $validator = new Rs256AuthorizationJwtValidator(OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY'));
        $token = FirebaseJwtTestFactory::encodeEs256([
            'iss' => 'http://localhost',
            'aud' => 'client-1',
            'sub' => 'user-1',
            'exp' => time() + 3600,
        ]);

        $this->assertNull($validator->validateJwtSignature($token));
    }

    public function testEs256ValidatorRejectsRs256Token(): void
    {
        $validator = new Es256AuthorizationJwtValidator(OidcFixtureKeys::publicEcPem());
        $token = FirebaseJwtTestFactory::authorizationForUserAndClient('user-1', 'client-1');

        $this->assertNull($validator->validateJwtSignature($token));
    }

    public function testValidatorsRejectUnknownKeyTokens(): void
    {
        $unknownRs256 = $this->unknownKeyRs256Token();
        $unknownEs256 = $this->unknownKeyEs256Token();

        $this->assertNull(
            (new Rs256AuthorizationJwtValidator(OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY')))
                ->validateJwtSignature($unknownRs256)
        );
        $this->assertNull(
            (new Es256AuthorizationJwtValidator(OidcFixtureKeys::publicEcPem()))
                ->validateJwtSignature($unknownEs256)
        );
    }

    public function testValidatorsRejectNoneToken(): void
    {
        $none = $this->noneToken();

        $this->assertNull(
            (new Rs256AuthorizationJwtValidator(OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY')))
                ->validateJwtSignature($none)
        );
        $this->assertNull(
            (new Es256AuthorizationJwtValidator(OidcFixtureKeys::publicEcPem()))
                ->validateJwtSignature($none)
        );
    }

    public function testValidatorsRejectTamperedToken(): void
    {
        $token = FirebaseJwtTestFactory::authorizationForUserAndClient('user-1', 'client-1');
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
        $parts[1] = $this->flipFirstChar($parts[1]);
        $tampered = implode('.', $parts);

        $this->assertNull(
            (new Rs256AuthorizationJwtValidator(OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY')))
                ->validateJwtSignature($tampered)
        );
    }

    public function testValidatorsRejectExpiredToken(): void
    {
        $expired = FirebaseJwtTestFactory::authorizationForUserAndClient(
            'user-1',
            'client-1',
            null,
            null,
            'http://localhost',
            -60
        );

        $this->assertNull(
            (new Rs256AuthorizationJwtValidator(OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY')))
                ->validateJwtSignature($expired)
        );
    }

    public function testFailedValidationLogsReasonForRs256(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);
        $validator = new Rs256AuthorizationJwtValidator(OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY'));

        $this->assertNull(
            $validator->validateJwtSignature(
                FirebaseJwtTestFactory::encodeEs256([
                    'iss' => 'http://localhost',
                    'aud' => 'client-1',
                    'sub' => 'user-1',
                    'exp' => time() + 3600,
                ]),
                $logger
            )
        );

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame(Level::Debug, $records[0]->level);
        $this->assertSame('jwt signature validation failed', $records[0]->message);
        $this->assertArrayHasKey('reason', $records[0]->context);
        $this->assertArrayHasKey('exception_class', $records[0]->context);
    }

    public function testFailedValidationLogsReason(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);
        $validator = new Es256AuthorizationJwtValidator(OidcFixtureKeys::publicEcPem());

        $this->assertNull(
            $validator->validateJwtSignature(
                FirebaseJwtTestFactory::authorizationForUserAndClient('user-1', 'client-1'),
                $logger
            )
        );

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame(Level::Debug, $records[0]->level);
        $this->assertSame('jwt signature validation failed', $records[0]->message);
        $this->assertArrayHasKey('reason', $records[0]->context);
        $this->assertArrayHasKey('exception_class', $records[0]->context);
    }

    public function testCompositeAcceptsRs256AndEs256Tokens(): void
    {
        $composite = $this->composite();
        $rs256 = FirebaseJwtTestFactory::authorizationForUserAndClient('user-1', 'client-1');
        $es256 = FirebaseJwtTestFactory::encodeEs256([
            'iss' => 'http://localhost',
            'aud' => 'client-1',
            'sub' => 'user-1',
            'exp' => time() + 3600,
        ]);

        $this->assertSame($es256, $composite->validateJwtSignature($es256));
        $this->assertSame($rs256, $composite->validateJwtSignature($rs256));
    }

    public function testCompositeRejectsUnknownKeyAndNoneTokens(): void
    {
        $composite = $this->composite();

        $this->assertNull($composite->validateJwtSignature($this->unknownKeyRs256Token()));
        $this->assertNull($composite->validateJwtSignature($this->unknownKeyEs256Token()));
        $this->assertNull($composite->validateJwtSignature($this->noneToken()));
    }

    public function testFromEnvironmentOverlapAcceptsEs256AndLegacyRs256(): void
    {
        FirebaseJwtTestFactory::ensureKeys();
        $saved = $this->overrideOAuthEnv([
            'OAUTH_PUBLIC_KEY' => OidcFixtureKeys::publicEcKeyPath(),
            'OAUTH_PUBLIC_KEY_PREVIOUS' => '/tmp/public.key',
        ]);

        try {
            $validator = CompositeAuthorizationJwtValidator::fromEnvironment();

            $es256 = FirebaseJwtTestFactory::encodeEs256([
                'iss' => 'http://localhost',
                'aud' => 'client-1',
                'sub' => 'user-1',
                'exp' => time() + 3600,
            ]);
            $rs256 = FirebaseJwtTestFactory::authorizationForUserAndClient('user-1', 'client-1');

            $this->assertSame($es256, $validator->validateJwtSignature($es256));
            $this->assertSame($rs256, $validator->validateJwtSignature($rs256));
            $this->assertNull($validator->validateJwtSignature($this->unknownKeyRs256Token()));
            $this->assertNull($validator->validateJwtSignature($this->unknownKeyEs256Token()));
            $this->assertNull($validator->validateJwtSignature($this->noneToken()));
            $this->assertNull($validator->validateJwtSignature($es256 . 'tampered'));
        } finally {
            $this->restoreOAuthEnv($saved);
        }
    }

    public function testFromEnvironmentSingleKeyRejectsLegacyRs256(): void
    {
        FirebaseJwtTestFactory::ensureKeys();
        $saved = $this->overrideOAuthEnv([
            'OAUTH_PUBLIC_KEY' => OidcFixtureKeys::publicEcKeyPath(),
            'OAUTH_PUBLIC_KEY_PREVIOUS' => null,
        ]);

        try {
            $validator = CompositeAuthorizationJwtValidator::fromEnvironment();

            $es256 = FirebaseJwtTestFactory::encodeEs256([
                'iss' => 'http://localhost',
                'aud' => 'client-1',
                'sub' => 'user-1',
                'exp' => time() + 3600,
            ]);
            $rs256 = FirebaseJwtTestFactory::authorizationForUserAndClient('user-1', 'client-1');

            $this->assertSame($es256, $validator->validateJwtSignature($es256));
            $this->assertNull($validator->validateJwtSignature($rs256));
        } finally {
            $this->restoreOAuthEnv($saved);
        }
    }

    public function testFromEnvironmentReadsPreviousKeyFromServerEnvWhenPutenvIsUnset(): void
    {
        // $_ENV wins over getenv(): the previous key must still be picked
        // up when it is set in $_ENV only (putenv cleared). Kills the
        // coalesce mutant that swaps the ?? operands in
        // CompositeAuthorizationJwtValidator::fromEnvironment() — the same
        // mutant M6 killed in the bearer composite; the identical line
        // survived here until M7.
        FirebaseJwtTestFactory::ensureKeys();
        $saved = $this->overrideOAuthEnv([
            'OAUTH_PUBLIC_KEY' => OidcFixtureKeys::publicEcKeyPath(),
            'OAUTH_PUBLIC_KEY_PREVIOUS' => '/tmp/public.key',
        ]);
        putenv('OAUTH_PUBLIC_KEY_PREVIOUS');

        try {
            $validator = CompositeAuthorizationJwtValidator::fromEnvironment();

            $rs256 = FirebaseJwtTestFactory::authorizationForUserAndClient('user-1', 'client-1');

            $this->assertSame($rs256, $validator->validateJwtSignature($rs256));
        } finally {
            $this->restoreOAuthEnv($saved);
        }
    }

    public function testValidatorFromPublicKeyPemThrowsForUnknownKeyTypes(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to parse public key for authorization JWT validation.');

        CompositeAuthorizationJwtValidator::validatorFromPublicKeyPem(
            "-----BEGIN PUBLIC KEY-----\nZ2FyYmFnZQ==\n-----END PUBLIC KEY-----\n"
        );
    }

    public function testValidatorFromPublicKeyPemThrowsForP384(): void
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'secp384r1',
        ]);
        $this->assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        $publicPem = $details['key'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Authorization JWT signing key must be an RSA or P-256 EC public key.');

        CompositeAuthorizationJwtValidator::validatorFromPublicKeyPem($publicPem);
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

    public function testCompositeTriesValidatorsInOrder(): void
    {
        $token = FirebaseJwtTestFactory::authorizationForUserAndClient('user-1', 'client-1');

        $first = $this->createMock(AuthorizationJwtValidator::class);
        $first->expects($this->once())
            ->method('validateJwtSignature')
            ->willReturn($token);
        $second = $this->createMock(AuthorizationJwtValidator::class);
        $second->expects($this->never())
            ->method('validateJwtSignature');

        $composite = new CompositeAuthorizationJwtValidator($first, $second);

        $this->assertSame($token, $composite->validateJwtSignature($token));
    }

    public function testCompositeFallsThroughToSecondValidator(): void
    {
        $token = FirebaseJwtTestFactory::authorizationForUserAndClient('user-1', 'client-1');

        $first = $this->createMock(AuthorizationJwtValidator::class);
        $first->expects($this->once())
            ->method('validateJwtSignature')
            ->willReturn(null);
        $second = $this->createMock(AuthorizationJwtValidator::class);
        $second->expects($this->once())
            ->method('validateJwtSignature')
            ->willReturn($token);

        $composite = new CompositeAuthorizationJwtValidator($first, $second);

        $this->assertSame($token, $composite->validateJwtSignature($token));
    }

    public function testCompositeWithNoValidatorsRejects(): void
    {
        $composite = new CompositeAuthorizationJwtValidator();

        $this->assertNull(
            $composite->validateJwtSignature(
                FirebaseJwtTestFactory::authorizationForUserAndClient('user-1', 'client-1')
            )
        );
    }

    private function composite(): CompositeAuthorizationJwtValidator
    {
        return new CompositeAuthorizationJwtValidator(
            new Es256AuthorizationJwtValidator(OidcFixtureKeys::publicEcPem()),
            new Rs256AuthorizationJwtValidator(OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY')),
        );
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

        return FirebaseJwt::encode([
            'iss' => 'http://localhost',
            'aud' => 'client-1',
            'sub' => 'user-1',
            'exp' => time() + 3600,
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

        return FirebaseJwt::encode([
            'iss' => 'http://localhost',
            'aud' => 'client-1',
            'sub' => 'user-1',
            'exp' => time() + 3600,
        ], $pem, 'ES256');
    }

    private function noneToken(): string
    {
        return $this->base64Url((string) json_encode(['alg' => 'none', 'typ' => 'JWT']))
            . '.' . $this->base64Url((string) json_encode([
                'iss' => 'http://localhost',
                'aud' => 'client-1',
                'sub' => 'user-1',
                'exp' => time() + 3600,
            ]))
            . '.';
    }

    private function flipFirstChar(string $segment): string
    {
        $first = $segment[0] === 'A' ? 'B' : 'A';

        return $first . substr($segment, 1);
    }

    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
