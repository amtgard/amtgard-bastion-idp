<?php

declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Common\Repositories;

use Amtgard\ActiveRecordOrm\Configuration\DataAccessPolicy\UncachedDataAccessPolicy;
use Amtgard\ActiveRecordOrm\Entity\EntityMapper;
use Amtgard\ActiveRecordOrm\Entity\Repository\RepositoryEntity;
use Amtgard\ActiveRecordOrm\Factory\TableFactory;
use Amtgard\ActiveRecordOrm\Interface\EntityInterface;
use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\IAM\Allowance\Policy;
use Amtgard\IAM\ClaimFactory;
use Amtgard\IAM\OrkServices;
use Amtgard\IdP\Utility\BuiltInOrkPolicyServices;
use Amtgard\IdP\Utility\OrnClaimRegistry;
use Optional\Optional;
use Psr\Log\LoggerInterface;
use Throwable;

class UserPolicyClaimRepository
{
    public const MAX_CLAIMS_PER_CLIENT = 25;

    private EntityMapper $userClaims;

    public function __construct(
        Database $database,
        UncachedDataAccessPolicy $tablePolicy,
        private LoggerInterface $logger,
    ) {
        $this->userClaims = EntityMapper::builder()
            ->table(TableFactory::build($database, $tablePolicy, 'user_policy_claims'))
            ->build();
    }

    /**
     * @return list<array{service: string, provisos: string, resource: string}>
     */
    public function listClaimsForUser(int $userDbId, ?string $service = null, ?int $clientDbId = null): array
    {
        $this->loadClaimsForUser($userDbId);

        $claims = [];
        while ($this->userClaims->next()) {
            if (!$this->matchesListFilters($service, $clientDbId)) {
                continue;
            }

            $claims[] = $this->currentClaimRow();
        }

        return $claims;
    }

    public function addClaim(
        int $userDbId,
        string $service,
        string $provisos,
        string $resource,
        int $updatedByUserDbId,
        ?int $clientDbId = null
    ): void {
        $this->assertValidOrnParts($service, $provisos, $resource);
        $this->assertThirdPartyClaimHasClient($service, $clientDbId);
        $this->assertClaimParses($service, $provisos, $resource);

        if ($this->claimExists($userDbId, $service, $provisos, $resource)) {
            return;
        }

        Optional::ofNullable($clientDbId)
            ->ifPresent(fn (int $scopedClientDbId) => $this->assertClientClaimCap($userDbId, $scopedClientDbId));

        $this->insertClaim($userDbId, $clientDbId, $updatedByUserDbId, $service, $provisos, $resource);
    }

    public function deleteClaim(int $userDbId, string $service, string $provisos, string $resource): bool
    {
        $this->assertValidOrnParts($service, $provisos, $resource);

        $this->userClaims->clear();
        $this->userClaims->query(
            'DELETE FROM user_policy_claims
             WHERE user_id = :user_id AND service = :service AND provisos = :provisos AND resource = :resource'
        );
        $this->userClaims->user_id = $userDbId;
        $this->userClaims->service = $service;
        $this->userClaims->provisos = $provisos;
        $this->userClaims->resource = $resource;
        $this->userClaims->execute();

        return true;
    }

    public function getUserPolicy(EntityInterface $user, ?int $forClientDbId = null): Policy
    {
        $userDbId = $this->resolveUserDbId($user);

        try {
            $this->loadClaimsForUser($userDbId);
            OrnClaimRegistry::registerForService(OrkServices::Idp->value);

            $policyClaims = [];
            while ($this->userClaims->next()) {
                $service = (string) $this->userClaims->service;
                $claimClientId = $this->userClaims->client_id ?? null;

                if (!$this->includeClaimInAuthorizationJwt($service, $claimClientId, $forClientDbId)) {
                    continue;
                }

                $claim = $this->parseStoredClaim($service, $userDbId);
                if ($claim !== null) {
                    $policyClaims[] = $claim;
                }
            }

            return new Policy($policyClaims);
        } catch (Throwable $e) {
            $this->logger->error('Failed to load user policy claims; using empty policy', [
                'user_id' => $userDbId,
                'detail' => $e->getMessage(),
            ]);

            return new Policy([]);
        }
    }

    private function resolveUserDbId(EntityInterface $user): int
    {
        if ($user instanceof RepositoryEntity) {
            return (int) $user->getInternalEntity()->id;
        }

        return (int) $user->id;
    }

    private function loadClaimsForUser(int $userDbId): void
    {
        $this->userClaims->clear();
        $this->userClaims->user_id = $userDbId;
        $this->userClaims->find();
    }

    /**
     * Authorization JWT policy includes:
     * - all built-in {@see OrkServices} claims (ORK platform + shared applications)
     * - custom integrator iam_service claims for the requesting OAuth client only
     */
    private function includeClaimInAuthorizationJwt(
        string $service,
        mixed $claimClientId,
        ?int $forClientDbId
    ): bool {
        if ($forClientDbId === null) {
            return true;
        }

        if (BuiltInOrkPolicyServices::isBuiltIn($service)) {
            return true;
        }

        return (int) $claimClientId === $forClientDbId;
    }

    private function matchesListFilters(?string $service, ?int $clientDbId): bool
    {
        if ($service !== null && $this->userClaims->service !== $service) {
            return false;
        }

        if ($clientDbId !== null && (int) ($this->userClaims->client_id ?? 0) !== $clientDbId) {
            return false;
        }

        return true;
    }

    /**
     * @return array{service: string, provisos: string, resource: string}
     */
    private function currentClaimRow(): array
    {
        return [
            'service' => (string) $this->userClaims->service,
            'provisos' => (string) $this->userClaims->provisos,
            'resource' => (string) $this->userClaims->resource,
        ];
    }

    private function parseStoredClaim(string $service, int $userDbId): mixed
    {
        $orn = $service . $this->userClaims->provisos . $this->userClaims->resource;

        try {
            OrnClaimRegistry::registerForService($service);

            return ClaimFactory::createOrn($orn);
        } catch (Throwable $e) {
            $this->logger->error('Skipping malformed user policy claim', [
                'user_id' => $userDbId,
                'orn' => $orn,
                'detail' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function assertThirdPartyClaimHasClient(string $service, ?int $clientDbId): void
    {
        if ($service === OrkServices::Idp->value) {
            return;
        }

        Optional::ofNullable($clientDbId)
            ->orElseThrow(new \InvalidArgumentException('client_id is required for third-party policy claims.'));
    }

    private function insertClaim(
        int $userDbId,
        ?int $clientDbId,
        int $updatedByUserDbId,
        string $service,
        string $provisos,
        string $resource
    ): void {
        $this->userClaims->clear();
        $this->userClaims->query(
            'INSERT INTO user_policy_claims (user_id, client_id, updated_by_user_id, updated_at, service, provisos, resource)
             VALUES (:user_id, :client_id, :updated_by_user_id, :updated_at, :service, :provisos, :resource)'
        );
        $this->userClaims->user_id = $userDbId;
        $this->userClaims->client_id = $clientDbId;
        $this->userClaims->updated_by_user_id = $updatedByUserDbId;
        $this->userClaims->updated_at = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->userClaims->service = $service;
        $this->userClaims->provisos = $provisos;
        $this->userClaims->resource = $resource;
        $this->userClaims->execute();
    }

    private function claimExists(int $userDbId, string $service, string $provisos, string $resource): bool
    {
        $this->userClaims->clear();
        $this->userClaims->user_id = $userDbId;
        $this->userClaims->service = $service;
        $this->userClaims->provisos = $provisos;
        $this->userClaims->resource = $resource;

        return $this->userClaims->find() > 0;
    }

    private function assertClientClaimCap(int $userDbId, int $clientDbId): void
    {
        $this->userClaims->clear();
        $this->userClaims->query(
            'SELECT COUNT(*) AS claim_count FROM user_policy_claims WHERE user_id = :user_id AND client_id = :client_id'
        );
        $this->userClaims->user_id = $userDbId;
        $this->userClaims->client_id = $clientDbId;
        $this->userClaims->execute();

        $count = Optional::ofNullable($this->userClaims->next() ? (int) ($this->userClaims->claim_count ?? 0) : null)
            ->orElse(0);

        if ($count >= self::MAX_CLAIMS_PER_CLIENT) {
            throw new \InvalidArgumentException(
                sprintf('At most %d policy claims are allowed per user for this client.', self::MAX_CLAIMS_PER_CLIENT)
            );
        }
    }

    private function assertValidOrnParts(string $service, string $provisos, string $resource): void
    {
        foreach ([['service', $service], ['provisos', $provisos], ['resource', $resource]] as [$label, $value]) {
            if ($value === '') {
                throw new \InvalidArgumentException("$label is required.");
            }
            if (strlen($value) > 50) {
                throw new \InvalidArgumentException("$label must be at most 50 characters.");
            }
        }
    }

    private function assertClaimParses(string $service, string $provisos, string $resource): void
    {
        OrnClaimRegistry::registerForService($service);
        try {
            ClaimFactory::createOrn($service . $provisos . $resource);
        } catch (Throwable $e) {
            throw new \InvalidArgumentException('Invalid ORN claim: ' . $e->getMessage(), 0, $e);
        }
    }
}
