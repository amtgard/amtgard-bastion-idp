<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Config;

use Amtgard\IdP\Controllers\Server\OAuth\OidcUserInfoController;
use Amtgard\IdP\Models\AmtgardIdpJwt;
use Amtgard\IdP\Models\AuthorizationJwtAssembler;
use Amtgard\IdP\Models\AuthorizationJwtValidator;
use Amtgard\IdP\Models\CompositeAuthorizationJwtValidator;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Tests\Support\FirebaseJwtTestFactory;
use Amtgard\IdP\Tests\Support\OidcFixtureKeys;
use Amtgard\IdP\Utility\Log\IdpLogging;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Ecdsa\Sha256 as EcdsaSha256;
use League\OAuth2\Server\ResourceServer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * M5 container wiring (ES256 design §5.4): the AuthorizationJwtValidator
 * binding resolves the overlap composite from the environment, the
 * AmtgardIdpJwt factory wires the container-bound Signer, and the
 * OidcUserInfoController factory wires the validator.
 */
class AuthorizationJwtValidatorBindingTest extends TestCase
{
    public function testValidatorBindingResolvesOverlapComposite(): void
    {
        FirebaseJwtTestFactory::ensureKeys();
        $saved = $this->overrideOAuthEnv([
            'OAUTH_PUBLIC_KEY' => OidcFixtureKeys::publicEcKeyPath(),
            'OAUTH_PUBLIC_KEY_PREVIOUS' => '/tmp/public.key',
        ]);

        try {
            $definitions = require __DIR__ . '/../../config/container/oauth-server.php';
            $validator = $definitions[AuthorizationJwtValidator::class]();

            $this->assertInstanceOf(CompositeAuthorizationJwtValidator::class, $validator);

            $es256 = FirebaseJwtTestFactory::encodeEs256([
                'iss' => 'http://localhost',
                'aud' => 'client-1',
                'sub' => 'user-1',
                'exp' => time() + 3600,
            ]);
            $rs256 = FirebaseJwtTestFactory::authorizationForUserAndClient('user-1', 'client-1');

            $this->assertSame($es256, $validator->validateJwtSignature($es256));
            $this->assertSame($rs256, $validator->validateJwtSignature($rs256));
        } finally {
            $this->restoreOAuthEnv($saved);
        }
    }

    public function testAmtgardIdpJwtFactoryWiresSigner(): void
    {
        $saved = $this->overrideOAuthEnv([
            'OAUTH_PRIVATE_KEY' => OidcFixtureKeys::privateEcPem(),
        ]);

        try {
            $definitions = require __DIR__ . '/../../config/container/oauth-server.php';
            $signer = $definitions[Signer::class]();
            $this->assertInstanceOf(EcdsaSha256::class, $signer);

            $container = $this->containerReturning([
                AuthorizationJwtAssembler::class => $this->createStub(AuthorizationJwtAssembler::class),
                RedisCacheRepository::class => $this->createStub(RedisCacheRepository::class),
                IdpLogging::class => \Amtgard\IdP\Tests\Utility\Log\IdpLoggingTestFactory::create(),
                Signer::class => $signer,
            ]);

            $idpJwt = $definitions[AmtgardIdpJwt::class]($container);
            $this->assertInstanceOf(AmtgardIdpJwt::class, $idpJwt);

            $injected = (new \ReflectionProperty(AmtgardIdpJwt::class, 'signer'))->getValue($idpJwt);
            $this->assertSame($signer, $injected);
        } finally {
            $this->restoreOAuthEnv($saved);
        }
    }

    public function testOidcUserInfoControllerFactoryWiresValidator(): void
    {
        $validator = $this->createStub(AuthorizationJwtValidator::class);
        $container = $this->containerReturning([
            ResourceServer::class => $this->createStub(ResourceServer::class),
            UserRepository::class => $this->createStub(UserRepository::class),
            AuthorizationJwtValidator::class => $validator,
        ]);

        $definitions = require __DIR__ . '/../../config/container/oauth-server.php';
        $controller = $definitions[OidcUserInfoController::class]($container);
        $this->assertInstanceOf(OidcUserInfoController::class, $controller);

        $injected = (new \ReflectionProperty(OidcUserInfoController::class, 'authorizationJwtValidator'))
            ->getValue($controller);
        $this->assertSame($validator, $injected);
    }

    /** @param array<string, mixed> $services */
    private function containerReturning(array $services): ContainerInterface
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static fn (string $id) => $services[$id]
                ?? throw new \RuntimeException("Unexpected container get({$id})")
        );

        return $container;
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
}
