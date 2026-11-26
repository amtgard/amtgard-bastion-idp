<?php

declare(strict_types=1);

namespace Amtgard\IdP\Models\Oidc;

use DateTimeZone;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use League\OAuth2\Server\AuthorizationValidators\AuthorizationValidatorInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * RS256 access-token validator (ES256 design §5.3). Mirrors League 8.5.5
 * BearerTokenValidator::validateAuthorization() with a fixed Rsa\Sha256
 * signer and the RSA public key; accepts RS256 tokens only. Do NOT subclass
 * League's BearerTokenValidator — it cannot work (design §3.2).
 *
 * M1 only: no production consumers yet (wired in M6). Psalm analyses
 * src/ only, so construction from config/ and use from tests does not
 * count as use.
 *
 * @psalm-suppress UnusedClass
 */
final class Rs256BearerTokenValidator implements AuthorizationValidatorInterface
{
    private readonly Configuration $jwtConfiguration;

    public function __construct(
        private readonly AccessTokenRepositoryInterface $accessTokenRepository,
        string $publicKeyPem,
        private readonly ?LoggerInterface $logger = null,
        ?\DateInterval $jwtValidAtDateLeeway = null,
    ) {
        $signer = new Sha256();
        $this->jwtConfiguration = Configuration::forSymmetricSigner(
            $signer,
            InMemory::plainText('empty', 'empty')
        );

        $clock = new SystemClock(new DateTimeZone(date_default_timezone_get()));
        $this->jwtConfiguration->setValidationConstraints(
            new LooseValidAt($clock, $jwtValidAtDateLeeway),
            new SignedWith($signer, InMemory::plainText($publicKeyPem, ''))
        );
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function validateAuthorization(ServerRequestInterface $request): ServerRequestInterface
    {
        if ($request->hasHeader('authorization') === false) {
            throw OAuthServerException::accessDenied('Missing "Authorization" header');
        }

        $header = $request->getHeader('authorization');
        $jwt = trim((string) preg_replace('/^\s*Bearer\s/', '', $header[0] ?? ''));

        try {
            // Attempt to parse the JWT
            $token = $this->jwtConfiguration->parser()->parse($jwt);
        } catch (\Lcobucci\JWT\Exception $exception) {
            throw OAuthServerException::accessDenied($exception->getMessage(), null, $exception);
        }

        if (!$token instanceof Plain) {
            throw OAuthServerException::accessDenied('Access token could not be verified');
        }

        try {
            // Attempt to validate the JWT
            $constraints = $this->jwtConfiguration->validationConstraints();
            $this->jwtConfiguration->validator()->assert($token, ...$constraints);
        } catch (RequiredConstraintsViolated $exception) {
            throw OAuthServerException::accessDenied('Access token could not be verified', null, $exception);
        }

        $claims = $token->claims();

        // Check if token has been revoked
        if ($this->accessTokenRepository->isAccessTokenRevoked($claims->get('jti'))) {
            throw OAuthServerException::accessDenied('Access token has been revoked');
        }

        $this->logger?->info('bearer token validated', ['alg' => 'RS256']);

        // Return the request with additional attributes
        return $request
            ->withAttribute('oauth_access_token_id', $claims->get('jti'))
            ->withAttribute('oauth_client_id', $this->convertSingleRecordAudToString($claims->get('aud')))
            ->withAttribute('oauth_user_id', $claims->get('sub'))
            ->withAttribute('oauth_scopes', $claims->get('scopes'));
    }

    /**
     * Convert single record arrays into strings to ensure backwards compatibility between v4 and v3.x of lcobucci/jwt
     *
     * @param mixed $aud
     */
    private function convertSingleRecordAudToString(mixed $aud): mixed
    {
        return \is_array($aud) && \count($aud) === 1 ? $aud[0] : $aud;
    }
}
