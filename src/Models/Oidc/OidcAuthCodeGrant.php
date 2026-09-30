<?php

declare(strict_types=1);

namespace Amtgard\IdP\Models\Oidc;

use Amtgard\IdP\Persistence\Server\Repositories\AuthCodeNonceLookup;
use DateInterval;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;

final class OidcAuthCodeGrant extends AuthCodeGrant
{
    public function __construct(
        AuthCodeRepositoryInterface $authCodeRepository,
        RefreshTokenRepositoryInterface $refreshTokenRepository,
        DateInterval $authCodeTTL,
        private OidcNonceContext $nonceContext
    ) {
        parent::__construct($authCodeRepository, $refreshTokenRepository, $authCodeTTL);
    }

    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        DateInterval $accessTokenTTL
    ) {
        $this->stashAuthorizationCodeNonce($request);

        return parent::respondToAccessTokenRequest($request, $responseType, $accessTokenTTL);
    }

    private function stashAuthorizationCodeNonce(ServerRequestInterface $request): void
    {
        $encryptedAuthCode = $this->getRequestParameter('code', $request, null);
        if (!is_string($encryptedAuthCode) || $encryptedAuthCode === '') {
            return;
        }

        $payload = $this->decodeAuthorizationCode($encryptedAuthCode);
        if (!is_object($payload) || !isset($payload->auth_code_id)) {
            return;
        }

        $nonce = $this->persistedNonce((string) $payload->auth_code_id);
        if (is_string($nonce) && $nonce !== '') {
            $this->nonceContext->set($nonce);
        }
    }

    private function decodeAuthorizationCode(string $encryptedAuthCode): mixed
    {
        try {
            return json_decode($this->decrypt($encryptedAuthCode));
        } catch (\Throwable) {
            return null;
        }
    }

    private function persistedNonce(string $authCodeId): ?string
    {
        $repository = $this->authCodeRepository;
        if (!$repository instanceof AuthCodeNonceLookup) {
            return null;
        }

        return $repository->findNonceByAuthCodeId($authCodeId);
    }
}
