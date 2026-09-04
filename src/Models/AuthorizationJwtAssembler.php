<?php

declare(strict_types=1);

namespace Amtgard\IdP\Models;

use Amtgard\ActiveRecordOrm\Interface\EntityInterface;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Common\Repositories\UserPolicy;
use Amtgard\IdP\Persistence\Common\Repositories\JwtChallenge;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Entities\Repository\UserJwtGeneration;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserJwtGenerationRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserLoginClientRepository;
use Amtgard\IdP\Utility\LoginSession;
use Amtgard\IdP\Utility\OrnClaimRegistry;
use Amtgard\IdP\Utility\Pvh;
use Optional\Optional;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Facade over the steps needed to assemble an authorization JWT payload.
 *
 * Integrator JWTs include:
 * - policy: built-in {@see \Amtgard\IAM\Catalog\ServiceCatalog} claims, plus custom claims for the requesting client
 * - client_metadata: per-login blob for the requesting client only (when present)
 */
final class AuthorizationJwtAssembler
{
    public const ISSUER = 'https://idp.amtgard.com';

    private ?UserJwtGeneration $lastGeneration = null;

    public function __construct(
        private UserPolicy $userPolicy,
        private JwtChallenge $jwtChallenge,
        private ClientRepository $clientRepository,
        private UserLoginClientRepository $metadataRepository,
        private UserLoginRepository $userLoginRepository,
        private LoggerInterface $logger,
        private UserJwtGenerationRepository $generationRepository,
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
        $this->lastGeneration = $this->applyPvhClaim($claims, $user, $clientContext);

        return $claims;
    }

    public function lastGeneration(): ?UserJwtGeneration
    {
        return $this->lastGeneration;
    }

    /**
     * Canonical policy_hash for (user, aud) using the same ORN register,
     * UserPolicy::toPolicyJson, metadata, and Pvh::canonicalMetadata path as mint.
     * Does not upsert a generation or sign a JWT.
     *
     * @return array{policy_hash: string, client_id: ?int}
     */
    public function computePolicyHashForAudience(EntityInterface $user, string $aud): array
    {
        $clientContext = $this->resolveClientContext($aud);
        $loginDbId = $this->resolveLoginDbId($user, null);
        $policyJson = $this->userPolicy->toPolicyJson($user, $clientContext->forClientDbId);
        $metadata = $this->metadataForAudience($clientContext->forClientDbId, $loginDbId);

        return [
            'policy_hash' => Pvh::policyHash($aud, $policyJson, Pvh::canonicalMetadata($metadata)),
            'client_id' => $clientContext->forClientDbId,
        ];
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function applyPvhClaim(
        array &$claims,
        EntityInterface $user,
        AuthorizationClientContext $clientContext
    ): ?UserJwtGeneration {
        $aud = $claims['aud'] ?? null;
        if (!is_string($aud) || $aud === '') {
            return null;
        }

        $policyJson = $claims['policy'] ?? '';
        if (!is_string($policyJson)) {
            $policyJson = '';
        }

        $policyHash = Pvh::policyHash(
            $aud,
            $policyJson,
            Pvh::canonicalMetadata($claims['client_metadata'] ?? null)
        );
        $nowMs = (int) floor(microtime(true) * 1000);
        $row = $this->generationRepository->upsert(
            (int) $user->id,
            (string) $user->userId,
            $clientContext->forClientDbId,
            $aud,
            $policyHash,
            $nowMs
        );
        $claims['pvh'] = $row->getPvh();

        return $row;
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

        try {
            // Custom ORN layouts are per-client; register before policy parsing.
            OrnClaimRegistry::registerForClient($client);
        } catch (Throwable $e) {
            $this->logger->error('Failed to register client ORN format; continuing without custom layout', [
                'client_id' => $client->getIdentifier(),
                'detail' => $e->getMessage(),
            ]);
        }

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
            'iss' => self::ISSUER,
            'orkid' => $user->orkUserId,
            'orkuser' => $user->username,
            'email' => $user->email,
            'policy' => $this->userPolicy->toPolicyJson($user, $forClientDbId),
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
