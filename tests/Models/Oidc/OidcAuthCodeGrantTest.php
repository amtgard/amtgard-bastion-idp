<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Models\Oidc;

use Amtgard\IdP\Models\Oidc\OidcAuthCodeGrant;
use Amtgard\IdP\Models\Oidc\OidcNonceContext;
use Amtgard\IdP\Persistence\Server\Repositories\AuthCodeNonceLookup;
use Amtgard\IdP\Tests\Support\OidcTokenExchangeHarness;
use DateInterval;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Slim\Psr7\Factory\ServerRequestFactory;

class OidcAuthCodeGrantTest extends TestCase
{
    public function testExtendsAuthCodeGrantAndDeclaresParentOnlyResponder(): void
    {
        $grant = new OidcAuthCodeGrant(
            $this->createStub(AuthCodeRepositoryInterface::class),
            $this->createStub(RefreshTokenRepositoryInterface::class),
            new DateInterval('PT10M'),
            new OidcNonceContext()
        );

        $this->assertInstanceOf(AuthCodeGrant::class, $grant);

        $method = new ReflectionMethod(OidcAuthCodeGrant::class, 'respondToAccessTokenRequest');
        $this->assertSame(OidcAuthCodeGrant::class, $method->getDeclaringClass()->getName());
    }

    public function testRespondToAccessTokenRequestIssuesTokensViaParent(): void
    {
        $body = OidcTokenExchangeHarness::exchange('openid');

        $this->assertArrayHasKey('access_token', $body);
        $this->assertArrayHasKey('id_token', $body);
    }

    public function testStashesPersistedNonceBeforeCallingParent(): void
    {
        $context = new OidcNonceContext();
        $grant = $this->grant($this->lookupRepo(['code-1' => 'from-db']), $context);
        $request = $this->tokenRequest($this->encrypt($grant, ['auth_code_id' => 'code-1']));

        $this->stash($grant, $request);

        $this->assertSame('from-db', $context->get());
    }

    public function testDoesNotStashWhenCodeParameterIsMissing(): void
    {
        $context = new OidcNonceContext();
        $grant = $this->grant($this->lookupRepo(['code-1' => 'from-db']), $context);

        $this->stash($grant, $this->tokenRequest([]));

        $this->assertNull($context->get());
    }

    public function testDoesNotStashWhenCodeParameterIsNotAString(): void
    {
        $context = new OidcNonceContext();
        $grant = $this->grant($this->lookupRepo(['code-1' => 'from-db']), $context);

        $this->stash($grant, $this->tokenRequest(['code' => ['not-a-string']]));

        $this->assertNull($context->get());
    }

    public function testDoesNotStashWhenCodeParameterIsEmpty(): void
    {
        $context = new OidcNonceContext();
        $grant = $this->grant($this->lookupRepo(['code-1' => 'from-db']), $context);

        $this->stash($grant, $this->tokenRequest(['code' => '']));

        $this->assertNull($context->get());
    }

    public function testDoesNotStashWhenDecryptFails(): void
    {
        $context = new OidcNonceContext();
        $grant = $this->grant($this->lookupRepo(['code-1' => 'from-db']), $context);

        $this->stash($grant, $this->tokenRequest(['code' => 'not-encrypted']));

        $this->assertNull($context->get());
    }

    public function testDoesNotStashWhenPayloadIsNotAnObject(): void
    {
        $context = new OidcNonceContext();
        $grant = $this->grant($this->lookupRepo(['code-1' => 'from-db']), $context);

        $this->stash($grant, $this->tokenRequest($this->encrypt($grant, [1, 2, 3])));

        $this->assertNull($context->get());
    }

    public function testDoesNotStashWhenAuthCodeIdIsMissing(): void
    {
        $context = new OidcNonceContext();
        $grant = $this->grant($this->lookupRepo(['code-1' => 'from-db']), $context);

        $this->stash($grant, $this->tokenRequest($this->encrypt($grant, ['client_id' => 'x'])));

        $this->assertNull($context->get());
    }

    public function testDoesNotStashWhenRepositoryDoesNotLookUpNonce(): void
    {
        $context = new OidcNonceContext();
        $grant = $this->grant($this->createStub(AuthCodeRepositoryInterface::class), $context);

        $this->stash($grant, $this->tokenRequest($this->encrypt($grant, ['auth_code_id' => 'code-1'])));

        $this->assertNull($context->get());
    }

    public function testDoesNotStashWhenPersistedNonceIsEmpty(): void
    {
        $context = new OidcNonceContext();
        $grant = $this->grant($this->lookupRepo(['code-1' => '']), $context);

        $this->stash($grant, $this->tokenRequest($this->encrypt($grant, ['auth_code_id' => 'code-1'])));

        $this->assertNull($context->get());
    }

    public function testDoesNotStashWhenDecryptedPayloadIsNotJson(): void
    {
        $context = new OidcNonceContext();
        $grant = $this->grant($this->lookupRepo(['code-1' => 'from-db']), $context);
        $encrypted = (new ReflectionMethod(OidcAuthCodeGrant::class, 'encrypt'))
            ->invoke($grant, 'not-json');

        $this->stash($grant, $this->tokenRequest(['code' => $encrypted]));

        $this->assertNull($context->get());
    }

    public function testStashesNonceWhenAuthCodeIdIsNumeric(): void
    {
        $context = new OidcNonceContext();
        $grant = $this->grant($this->lookupRepo(['99' => 'from-int']), $context);

        $this->stash($grant, $this->tokenRequest($this->encrypt($grant, ['auth_code_id' => 99])));

        $this->assertSame('from-int', $context->get());
    }

    public function testDoesNotStashWhenPersistedNonceIsMissing(): void
    {
        $context = new OidcNonceContext();
        $grant = $this->grant($this->lookupRepo([]), $context);

        $this->stash($grant, $this->tokenRequest($this->encrypt($grant, ['auth_code_id' => 'missing'])));

        $this->assertNull($context->get());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function tokenRequest(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/oauth/token')
            ->withParsedBody($body);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encrypt(OidcAuthCodeGrant $grant, array $payload): array
    {
        $encrypted = (new ReflectionMethod(OidcAuthCodeGrant::class, 'encrypt'))
            ->invoke($grant, json_encode($payload, JSON_THROW_ON_ERROR));

        return ['code' => $encrypted];
    }

    private function stash(OidcAuthCodeGrant $grant, \Psr\Http\Message\ServerRequestInterface $request): void
    {
        (new ReflectionMethod(OidcAuthCodeGrant::class, 'stashAuthorizationCodeNonce'))
            ->invoke($grant, $request);
    }

    private function grant(AuthCodeRepositoryInterface $repository, OidcNonceContext $context): OidcAuthCodeGrant
    {
        $grant = new OidcAuthCodeGrant(
            $repository,
            $this->createStub(RefreshTokenRepositoryInterface::class),
            new DateInterval('PT10M'),
            $context
        );
        $grant->setEncryptionKey(str_repeat('a', 64));

        return $grant;
    }

    /**
     * @param array<string, string> $nonces
     */
    private function lookupRepo(array $nonces): AuthCodeRepositoryInterface
    {
        return new class ($nonces) implements AuthCodeRepositoryInterface, AuthCodeNonceLookup {
            /** @param array<string, string> $nonces */
            public function __construct(private array $nonces)
            {
            }

            public function getNewAuthCode(): AuthCodeEntityInterface
            {
                throw new \RuntimeException('not used');
            }

            public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
            {
            }

            public function revokeAuthCode($codeId): void
            {
            }

            public function isAuthCodeRevoked($codeId): bool
            {
                return false;
            }

            public function findNonceByAuthCodeId(string $authCodeId): ?string
            {
                return $this->nonces[$authCodeId] ?? null;
            }
        };
    }
}
