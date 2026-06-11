<?php

declare(strict_types=1);

namespace Amtgard\IdP\Models;

use Amtgard\ActiveRecordOrm\Interface\EntityInterface;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Common\Repositories\UserPolicy;
use Amtgard\IdP\Persistence\Common\Repositories\JwtChallenge;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserLoginClientRepository;
use Amtgard\IdP\Utility\LoginSession;
use Amtgard\IdP\Utility\OrnClaimRegistry;
use Optional\Optional;

/**
 * Facade over the steps needed to assemble an authorization JWT payload.
 */
final class AuthorizationJwtAssembler
{
    public function __construct(
        private UserPolicy $userPolicy,
        private JwtChallenge $jwtChallenge,
        private ClientRepository $clientRepository,
        private UserLoginClientRepository $metadataRepository,
        private UserLoginRepository $userLoginRepository,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildClaims(
        EntityInterface $user,
        ?string $oauthClientId = null,
        ?int $loginDbId = null
    ): array {
        $audience = $this->resolveAudience($oauthClientId);
        $clientContext = $this->resolveClientContext($audience);
        $resolvedLoginDbId = $this->resolveLoginDbId($user, $loginDbId);

        $claims = $this->baseClaims($user, $clientContext->forClientDbId);
        $this->applyAudienceClaims($claims, $audience, $clientContext, $resolvedLoginDbId);

        return $claims;
    }

    public function validateJwtChallenge(string $jwt): bool
    {
        return $this->jwtChallenge->validateChallenge($jwt);
    }

    private function resolveAudience(?string $oauthClientId): ?string
    {
        return Optional::ofNullable($oauthClientId)
            ->orElseGet(fn () => $_SESSION['client_id'] ?? null);
    }

    private function resolveClientContext(?string $audience): AuthorizationClientContext
    {
        $client = Optional::ofNullable($audience)
            ->map(fn (string $clientIdentifier) => $this->clientRepository->findClientByIdentifier($clientIdentifier))
            ->orElse(null);

        if (!$client instanceof Client) {
            return new AuthorizationClientContext(null);
        }

        // Custom ORN layouts are per-client; register before policy parsing.
        OrnClaimRegistry::registerForClient($client);

        return new AuthorizationClientContext($client->getId());
    }

    private function resolveLoginDbId(EntityInterface $user, ?int $loginDbId): ?int
    {
        return Optional::ofNullable($loginDbId)
            ->orElseGet(fn () => Optional::ofNullable(LoginSession::getLoginId())
                ->orElseGet(fn () => $this->userLoginRepository->resolveDefaultLoginIdForUser((int) $user->id)));
    }

    /**
     * @return array<string, mixed>
     */
    private function baseClaims(EntityInterface $user, ?int $forClientDbId): array
    {
        return [
            'iat' => time(),
            'sub' => $user->userId,
            'iss' => 'https://idp.amtgard.com',
            'orkid' => $user->orkUserId,
            'orkuser' => $user->username,
            'email' => $user->email,
            'policy' => $this->userPolicy->getUserPolicy($user, $forClientDbId)->toJson(),
            'challenge' => $this->jwtChallenge->createChallenge($user),
            'exp' => time() + 3600,
        ];
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function applyAudienceClaims(
        array &$claims,
        ?string $audience,
        AuthorizationClientContext $clientContext,
        ?int $loginDbId
    ): void {
        if ($audience === null || $audience === '') {
            return;
        }

        $claims['aud'] = $audience;

        Optional::ofNullable($this->metadataForAudience($clientContext->forClientDbId, $loginDbId))
            ->ifPresent(function (mixed $metadata) use (&$claims): void {
                // Metadata is stored per login×client so OAuth apps only see the active login row.
                $claims['client_metadata'] = $metadata;
            });
    }

    private function metadataForAudience(?int $forClientDbId, ?int $loginDbId): mixed
    {
        return Optional::ofNullable($forClientDbId)
            ->map(fn (int $clientDbId) => Optional::ofNullable($loginDbId)
                ->map(fn (int $resolvedLoginId) => $this->metadataRepository->getMetadataForJwt(
                    $resolvedLoginId,
                    $clientDbId
                ))
                ->orElse(null))
            ->orElse(null);
    }
}
