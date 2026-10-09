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
use Amtgard\IdP\Utility\Log\IdpLogging;
use Amtgard\IdP\Utility\OrnClaimRegistry;
use Amtgard\IdP\Utility\Pvh;
use Optional\Optional;
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
        private IdpLogging $idpLogging,
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
     * Compact heartbeat claims from a fat authorization payload.
     * Only `sub`, `aud`, `iss`, `exp`, `pvh` (and `iat` when present on the fat token).
     * `exp` is copied — do not extend on validate.
     *
     * @param array<string, mixed> $fatClaims
     * @return array<string, mixed>
     */
    public static function compactClaims(array $fatClaims): array
    {
        $compact = [];
        foreach (['sub', 'aud', 'iss', 'exp', 'pvh', 'iat'] as $key) {
            if (array_key_exists($key, $fatClaims)) {
                $compact[$key] = $fatClaims[$key];
            }
        }

        return $compact;
    }

    /**
     * Canonical policy_hash for (user, aud) using the same ORN register,
     * UserPolicy::toPolicyJson, metadata, and Pvh::canonicalMetadata path as mint.
     * Does not persist a generation or sign a JWT.
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
            $this->idpLogging->pvh()->debug('authorization jwt pvh skipped', [
                'reason' => 'missing_aud',
            ]);

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
        $row = $this->generationRepository->saveForPolicyHash(
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
        if ($oauthClientId !== null && $oauthClientId !== '') {
            return $oauthClientId;
        }

        $sessionAud = $_SESSION['client_id'] ?? null;
        if (is_string($sessionAud) && $sessionAud !== '') {
            $this->idpLogging->app()->debug('authorization jwt audience session fallback', [
                'aud' => $sessionAud,
            ]);
        }

        return is_string($sessionAud) ? $sessionAud : null;
    }

    private function resolveClientContext(?string $audience): AuthorizationClientContext
    {
        if ($audience === null || $audience === '') {
            return new AuthorizationClientContext(null);
        }

        $client = $this->clientRepository->findClientByIdentifier($audience);

        if (!$client instanceof Client) {
            $this->idpLogging->app()->debug('authorization jwt unknown audience client', [
                'aud' => $audience,
            ]);

            return new AuthorizationClientContext(null);
        }

        try {
            OrnClaimRegistry::registerForClient($client);
        } catch (Throwable $e) {
            $this->idpLogging->app()->error('authorization jwt orn register fallback', [
                'client_id' => $client->getIdentifier(),
                'detail' => $e->getMessage(),
            ]);
        }

        return new AuthorizationClientContext($client->getId());
    }

    private function resolveLoginDbId(EntityInterface $user, ?int $loginDbId): ?int
    {
        if ($loginDbId !== null) {
            return $loginDbId;
        }

        $sessionLoginId = LoginSession::getLoginId();
        if ($sessionLoginId !== null) {
            $this->idpLogging->app()->debug('authorization jwt login id fallback', [
                'user_id' => (int) $user->id,
                'login_id' => $sessionLoginId,
            ]);

            return $sessionLoginId;
        }

        $defaultLoginId = $this->userLoginRepository->resolveDefaultLoginIdForUser((int) $user->id);
        if ($defaultLoginId !== null) {
            $this->idpLogging->app()->debug('authorization jwt login id fallback', [
                'user_id' => (int) $user->id,
                'login_id' => $defaultLoginId,
            ]);
        }

        return $defaultLoginId;
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
        Optional::ofNullable($audience)
            ->filter(fn (string $value) => $value !== '')
            ->ifPresent(function (string $value) use (&$claims, $clientContext, $loginDbId): void {
                $claims['aud'] = $value;

                Optional::ofNullable($this->metadataForAudience($clientContext->forClientDbId, $loginDbId))
                    ->ifPresent(function (mixed $metadata) use (&$claims): void {
                        $claims['client_metadata'] = $metadata;
                    });
            });
    }

    private function metadataForAudience(?int $forClientDbId, ?int $loginDbId): mixed
    {
        if ($forClientDbId === null) {
            return null;
        }

        if ($loginDbId === null) {
            $this->idpLogging->app()->debug('authorization jwt metadata miss', [
                'client_id' => $forClientDbId,
                'login_id' => null,
            ]);

            return null;
        }

        $metadata = $this->metadataRepository->getMetadataForJwt($loginDbId, $forClientDbId);
        if ($metadata === null) {
            $this->idpLogging->app()->debug('authorization jwt metadata miss', [
                'client_id' => $forClientDbId,
                'login_id' => $loginDbId,
            ]);
        }

        return $metadata;
    }
}
