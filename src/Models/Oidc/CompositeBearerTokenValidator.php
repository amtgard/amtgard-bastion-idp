<?php

declare(strict_types=1);

namespace Amtgard\IdP\Models\Oidc;

use League\OAuth2\Server\AuthorizationValidators\AuthorizationValidatorInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Tries each bound bearer-token validator in order (ES256 design §5.3) and
 * returns the first success. If every bound implementation rejects, the
 * first failure is surfaced with League's exact exception types and
 * messages, so the userinfo endpoint's HTTP behaviour is unchanged.
 *
 * M1 only: no production consumers yet (wired in M6). Psalm analyses
 * src/ only, so construction from config/ and use from tests does not
 * count as use.
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
