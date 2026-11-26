<?php

declare(strict_types=1);

namespace Amtgard\IdP\Models;

use Amtgard\IdP\Utility\OAuthKeyMaterial;
use Psr\Log\LoggerInterface;

/**
 * Tries each bound authorization-JWT validator in turn, ES256 current
 * first, then the retiring RS256 (ES256 design §5.4). Try-in-turn with
 * single Keys, not a key array: firebase requires a kid for array lookup
 * and RS256 tokens minted before this change carry no kid.
 *
 * Wired in M5: the container binds AuthorizationJwtValidator to the
 * composite built from the environment (fromEnvironment()), and
 * Jwt::validateJwtRequest() builds the same composite for the static
 * call sites that cannot take injected dependencies.
 *
 * @psalm-suppress UnusedClass
 */
final class CompositeAuthorizationJwtValidator implements AuthorizationJwtValidator
{
    /** @var list<AuthorizationJwtValidator> */
    private readonly array $validators;

    public function __construct(AuthorizationJwtValidator ...$validators)
    {
        $this->validators = $validators;
    }

    /**
     * Build the overlap composite from the environment (ES256 design
     * §5.4/§5.7): the current key from OAUTH_PUBLIC_KEY, plus the
     * retiring key from OAUTH_PUBLIC_KEY_PREVIOUS when set. Absence of
     * the previous key means single-key validation. The env mapping for
     * OAUTH_PUBLIC_KEY_PREVIOUS lands in M7; until then the variable is
     * read directly here.
     */
    public static function fromEnvironment(): self
    {
        $validators = [self::validatorFromPublicKeyPem(OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY'))];

        $previous = $_ENV['OAUTH_PUBLIC_KEY_PREVIOUS'] ?? getenv('OAUTH_PUBLIC_KEY_PREVIOUS');
        if (is_string($previous) && $previous !== '') {
            $validators[] = self::validatorFromPublicKeyPem(
                OAuthKeyMaterial::readFromEnv('OAUTH_PUBLIC_KEY_PREVIOUS')
            );
        }

        return new self(...$validators);
    }

    /**
     * Validator construction (ES256 design §5.4): the single place that
     * maps a public-key PEM to its validator implementation. RSA details
     * build an Rs256AuthorizationJwtValidator; P-256 EC details build an
     * Es256AuthorizationJwtValidator. Anything else throws at
     * container-build time. Mirrors JwksFactory::providerFromPublicKeyPem().
     */
    public static function validatorFromPublicKeyPem(string $pem): AuthorizationJwtValidator
    {
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new \RuntimeException('Unable to parse public key for authorization JWT validation.');
        }

        $details = openssl_pkey_get_details($key);
        $rsa = \is_array($details) ? ($details['rsa'] ?? null) : null;
        if (\is_array($rsa)) {
            return new Rs256AuthorizationJwtValidator($pem);
        }

        $ec = \is_array($details) ? ($details['ec'] ?? null) : null;
        if (\is_array($ec) && ($ec['curve_name'] ?? null) === 'prime256v1') {
            return new Es256AuthorizationJwtValidator($pem);
        }

        throw new \RuntimeException('Authorization JWT signing key must be an RSA or P-256 EC public key.');
    }

    #[\Override]
    public function validateJwtSignature(string $putativeJwt, ?LoggerInterface $logger = null): ?string
    {
        foreach ($this->validators as $validator) {
            $validated = $validator->validateJwtSignature($putativeJwt, $logger);
            if ($validated !== null) {
                return $validated;
            }
        }

        return null;
    }
}
