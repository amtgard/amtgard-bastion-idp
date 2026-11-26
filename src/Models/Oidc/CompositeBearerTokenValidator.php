<?php

declare(strict_types=1);

namespace Amtgard\IdP\Models\Oidc;

use Amtgard\IdP\Utility\OAuthKeyMaterial;
use League\OAuth2\Server\AuthorizationValidators\AuthorizationValidatorInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Tries each bound bearer-token validator in order (ES256 design §5.3) and
 * returns the first success. If every bound implementation rejects, the
 * first failure is surfaced with League's exact exception types and
 * messages, so the userinfo endpoint's HTTP behaviour is unchanged.
 *
 * Wired in M6: the ResourceServer container factory passes the composite
 * built from the environment (fromEnvironment()) as the 3rd constructor
 * argument. Psalm analyses src/ only, so construction from config/ and
 * use from tests does not count as use.
 *
 * @psalm-suppress UnusedClass
 */
final class CompositeBearerTokenValidator implements AuthorizationValidatorInterface
{
    /** @var list<AuthorizationValidatorInterface> */
    private readonly array $validators;

    public function __construct(AuthorizationValidatorInterface ...$validators)
    {
        $this->validators = $validators;
    }

    /**
     * Build the overlap composite from the environment (ES256 design
     * §5.3/§5.7): the current key from OAUTH_PUBLIC_KEY, plus the
     * retiring key from OAUTH_PUBLIC_KEY_PREVIOUS when set (mapped from
     * oauth-public.key.previous in config/load-env.php). Absence of
     * the previous key means single-key validation.
     */
    public static function fromEnvironment(
        AccessTokenRepositoryInterface $accessTokenRepository,
        ?LoggerInterface $logger = null,
    ): self {
        $validators = [
            self::validatorFromPublicKeyPem(
                OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY'),
                $accessTokenRepository,
                $logger,
            ),
        ];

        $previous = $_ENV['OAUTH_PUBLIC_KEY_PREVIOUS'] ?? getenv('OAUTH_PUBLIC_KEY_PREVIOUS');
        if (is_string($previous) && $previous !== '') {
            $validators[] = self::validatorFromPublicKeyPem(
                OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY_PREVIOUS'),
                $accessTokenRepository,
                $logger,
            );
        }

        return new self(...$validators);
    }

    /**
     * Validator construction (ES256 design §5.3): the single place that
     * maps a public-key PEM to its bearer-token validator implementation.
     * RSA details build an Rs256BearerTokenValidator; P-256 EC details
     * build an Es256BearerTokenValidator. Anything else throws at
     * container-build time. Mirrors
     * CompositeAuthorizationJwtValidator::validatorFromPublicKeyPem() and
     * JwksFactory::providerFromPublicKeyPem().
     */
    public static function validatorFromPublicKeyPem(
        string $pem,
        AccessTokenRepositoryInterface $accessTokenRepository,
        ?LoggerInterface $logger = null,
    ): AuthorizationValidatorInterface {
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new \RuntimeException('Unable to parse public key for bearer token validation.');
        }

        $details = openssl_pkey_get_details($key);
        $rsa = \is_array($details) ? ($details['rsa'] ?? null) : null;
        if (\is_array($rsa)) {
            return new Rs256BearerTokenValidator($accessTokenRepository, $pem, $logger);
        }

        $ec = \is_array($details) ? ($details['ec'] ?? null) : null;
        if (\is_array($ec) && ($ec['curve_name'] ?? null) === 'prime256v1') {
            return new Es256BearerTokenValidator($accessTokenRepository, $pem, $logger);
        }

        throw new \RuntimeException('Bearer token signing key must be an RSA or P-256 EC public key.');
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function validateAuthorization(ServerRequestInterface $request): ServerRequestInterface
    {
        $firstFailure = null;
        foreach ($this->validators as $validator) {
            try {
                return $validator->validateAuthorization($request);
            } catch (OAuthServerException $exception) {
                $firstFailure ??= $exception;
            }
        }

        throw $firstFailure ?? OAuthServerException::accessDenied('Access token could not be verified');
    }
}
