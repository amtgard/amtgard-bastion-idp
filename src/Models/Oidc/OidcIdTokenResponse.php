<?php

declare(strict_types=1);

namespace Amtgard\IdP\Models\Oidc;

use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use OpenIDConnectServer\ClaimExtractor;
use OpenIDConnectServer\Entities\ClaimSetInterface;
use OpenIDConnectServer\IdTokenResponse;
use OpenIDConnectServer\Repositories\IdentityProviderInterface;

final class OidcIdTokenResponse extends IdTokenResponse
{
    /**
     * Signer injected by OAuthServerConfiguration::build() from the
     * container-bound Signer (§5.1a) through setSigner()
     * (ES256 design §5.2). Null until setSigner() is called.
     */
    private ?Signer $signer = null;

    public function __construct(
        IdentityProviderInterface $identityProvider,
        ClaimExtractor $claimExtractor,
        ?string $keyIdentifier = null,
        private ?OidcNonceContext $nonceContext = null
    ) {
        parent::__construct($identityProvider, $claimExtractor, $keyIdentifier);
    }

    public function setSigner(Signer $signer): void
    {
        $this->signer = $signer;
    }

    protected function getBuilder(AccessTokenEntityInterface $accessToken, UserEntityInterface $userEntity)
    {
        $claimsFormatter = ChainedFormatter::withUnixTimestampDates();
        $builder = new Builder(new JoseEncoder(), $claimsFormatter);

        $builder = $builder
            ->permittedFor($accessToken->getClient()->getIdentifier())
            ->issuedBy(rtrim((string) ($_ENV['APP_URL'] ?? ''), '/'))
            ->issuedAt(new \DateTimeImmutable())
            ->expiresAt($accessToken->getExpiryDateTime())
            ->relatedTo((string) $userEntity->getIdentifier());

        $nonce = $this->nonceContext?->get();
        if (is_string($nonce) && $nonce !== '') {
            $builder = $builder->withClaim('nonce', $nonce);
        }
        $this->nonceContext?->clear();

        return $builder;
    }

    /**
     * Duplicate of the parent's getExtraParams() with the signer taken
     * from the injected Signer instead of the parent's hardcoded
     * Rsa\Sha256 (ES256 design §5.2). The parent's isOpenIDRequest()
     * is private, so the scope check is duplicated inline below; the
     * parent's key-resolution logic (contents first, then file path)
     * is kept unchanged, except the file-path branch: the parent's
     * LocalFileReference was removed in lcobucci/jwt 5.x, so this
     * reads the file contents into InMemory instead (same key
     * material, same result). Fail fast (D7): a missing signer throws
     * instead of falling back to RS256.
     */
    #[\Override]
    protected function getExtraParams(AccessTokenEntityInterface $accessToken): array
    {
        if ($this->signer === null) {
            throw new \RuntimeException('OIDC ID token signer was not injected.');
        }

        $isOpenIdRequest = false;
        foreach ($accessToken->getScopes() as $scope) {
            if ($scope->getIdentifier() === 'openid') {
                $isOpenIdRequest = true;
                break;
            }
        }

        if ($isOpenIdRequest === false) {
            return [];
        }

        /** @var UserEntityInterface $userEntity */
        $userEntity = $this->identityProvider->getUserEntityByIdentifier($accessToken->getUserIdentifier());

        if (false === is_a($userEntity, UserEntityInterface::class)) {
            throw new \RuntimeException('UserEntity must implement UserEntityInterface');
        } elseif (false === is_a($userEntity, ClaimSetInterface::class)) {
            throw new \RuntimeException('UserEntity must implement ClaimSetInterface');
        }

        // Add required id_token claims
        $builder = $this->getBuilder($accessToken, $userEntity);

        // Need a claim factory here to reduce the number of claims by provided scope.
        $claims = $this->claimExtractor->extract($accessToken->getScopes(), $userEntity->getClaims());

        foreach ($claims as $claimName => $claimValue) {
            $builder = $builder->withClaim($claimName, $claimValue);
        }

        if ($this->keyIdentifier !== null) {
            $builder = $builder->withHeader('kid', $this->keyIdentifier);
        }

        if (
            method_exists($this->privateKey, 'getKeyContents')
            && !empty($this->privateKey->getKeyContents())
        ) {
            $key = InMemory::plainText(
                $this->privateKey->getKeyContents(),
                (string) $this->privateKey->getPassPhrase()
            );
        } else {
            $key = InMemory::plainText(
                (string) file_get_contents((string) $this->privateKey->getKeyPath()),
                (string) $this->privateKey->getPassPhrase()
            );
        }

        $token = $builder->getToken($this->signer, $key);

        return [
            'id_token' => $token->toString(),
        ];
    }
}
