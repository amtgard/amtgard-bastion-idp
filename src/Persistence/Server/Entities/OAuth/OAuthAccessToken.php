<?php

declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Server\Entities\OAuth;

use Amtgard\IdP\Persistence\Server\Entities\Repository\AccessToken;
use Amtgard\IdP\Persistence\Server\Entities\SerializationTrait;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Key\InMemory;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

class OAuthAccessToken implements AccessTokenEntityInterface
{
    use AccessTokenTrait;
    use TokenEntityTrait;
    use EntityTrait;
    use Builder;
    use Data;
    use SerializationTrait;

    private AccessToken $accessTokenEntity;

    /**
     * Signer injected by AccessTokenRepository::getNewToken() via the
     * builder (ES256 design §5.1). The repository receives it from the
     * container-bound Signer (§5.1a) through setSigner().
     */
    private ?Signer $signer = null;

    /**
     * Build the JWT configuration with the injected signer instead of the
     * trait's hardcoded Rsa\Sha256 (ES256 design §5.1). The verification
     * key is the trait's own 'empty' placeholder: access-token
     * minting never verifies, and forAsymmetricSigner requires both
     * arguments. Fail fast (D7): a missing signer throws instead of
     * falling back to RS256.
     */
    public function initJwtConfiguration()
    {
        if ($this->signer === null) {
            throw new \RuntimeException('OAuth access token signer was not injected.');
        }

        $this->jwtConfiguration = Configuration::forAsymmetricSigner(
            $this->signer,
            InMemory::plainText($this->privateKey->getKeyContents(), $this->privateKey->getPassPhrase() ?? ''),
            InMemory::plainText('empty', 'empty')
        );
    }
}
