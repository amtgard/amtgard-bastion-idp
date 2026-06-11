<?php

declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Common\Repositories;

use Amtgard\ActiveRecordOrm\Configuration\DataAccessPolicy\UncachedDataAccessPolicy;
use Amtgard\ActiveRecordOrm\Entity\EntityMapper;
use Amtgard\ActiveRecordOrm\Factory\TableFactory;
use Amtgard\ActiveRecordOrm\Interface\EntityInterface;
use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\IAM\Allowance\Policy;
use Amtgard\IAM\ClaimFactory;
use Amtgard\IAM\OrkServices;
use Amtgard\IdP\Utility\Exception\MalformedUserPolicyException;
use Amtgard\IdP\Utility\OrnClaimRegistry;
use Throwable;

class UserPolicyClaimRepository
{
    public const MAX_CLAIMS_PER_CLIENT = 25;

    private EntityMapper $userClaims;

    public function __construct(Database $database, UncachedDataAccessPolicy $tablePolicy)
    {
        $this->userClaims = EntityMapper::builder()
            ->table(TableFactory::build($database, $tablePolicy, 'user_policy_claims'))
            ->build();
    }

    /**
     * @return list<array{service: string, provisos: string, resource: string}>
     */
    public function listClaimsForUser(int $userDbId, ?string $service = null, ?int $clientDbId = null): array
    {
        $this->userClaims->clear();
        $this->userClaims->user_id = $userDbId;
        $this->userClaims->find();

        $claims = [];
        while ($this->userClaims->next()) {
            if ($service !== null && $this->userClaims->service !== $service) {
                continue;
            }
            if ($clientDbId !== null && (int) ($this->userClaims->client_id ?? 0) !== $clientDbId) {
                continue;
            }
            $claims[] = [
                'service' => (string) $this->userClaims->service,
                'provisos' => (string) $this->userClaims->provisos,
                'resource' => (string) $this->userClaims->resource,
            ];
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
        if ($service !== OrkServices::Idp->value && $clientDbId === null) {
            throw new \InvalidArgumentException('client_id is required for third-party policy claims.');
        }
        $this->assertClaimParses($service, $provisos, $resource);

        if ($this->claimExists($userDbId, $service, $provisos, $resource)) {
            return;
        }

        if ($clientDbId !== null) {
            $this->assertClientClaimCap($userDbId, $clientDbId);
        }

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
        $this->userClaims->clear();
        $this->userClaims->user_id = $user->id;
        $policyClaims = [];
        $this->userClaims->find();
        OrnClaimRegistry::registerForService(OrkServices::Idp->value);
        while ($this->userClaims->next()) {
            $service = (string) $this->userClaims->service;
            $claimClientId = $this->userClaims->client_id ?? null;

            if ($forClientDbId !== null) {
                if ($service !== OrkServices::Idp->value && (int) $claimClientId !== $forClientDbId) {
                    continue;
                }
            }

            try {
                OrnClaimRegistry::registerForService($service);
                $orn = $service . $this->userClaims->provisos . $this->userClaims->resource;
                $policyClaims[] = ClaimFactory::createOrn($orn);
            } catch (Throwable $e) {
                throw new MalformedUserPolicyException($e);
            }
        }

        return new Policy($policyClaims);
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
        if (!$this->userClaims->next()) {
            return;
        }

        $count = (int) ($this->userClaims->claim_count ?? 0);
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
