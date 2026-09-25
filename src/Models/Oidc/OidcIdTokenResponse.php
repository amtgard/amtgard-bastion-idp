<?php

declare(strict_types=1);

namespace Amtgard\IdP\Models\Oidc;

use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Builder;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use OpenIDConnectServer\ClaimExtractor;
use OpenIDConnectServer\IdTokenResponse;
use OpenIDConnectServer\Repositories\IdentityProviderInterface;

final class OidcIdTokenResponse extends IdTokenResponse
{
    public function __construct(
        IdentityProviderInterface $identityProvider,
        ClaimExtractor $claimExtractor,
        ?string $keyIdentifier = null,
        private ?OidcNonceContext $nonceContext = null
    ) {
        parent::__construct($identityProvider, $claimExtractor, $keyIdentifier);
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
}
