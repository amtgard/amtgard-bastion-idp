<?php

declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Common\Repositories;

use Amtgard\ActiveRecordOrm\Entity\EntityMapper;
use Amtgard\IAM\Catalog\ServiceCatalog;
use Optional\Optional;

final class UserPolicyClaimWriter
{
    public function __construct(
        private EntityMapper $userClaims,
        private ClaimOrnValidator $ornValidator,
    ) {
    }

    public function addClaim(
        int $userDbId,
        string $service,
        string $provisos,
        string $resource,
        int $updatedByUserDbId,
        ?int $clientDbId = null
    ): void {
        $this->ornValidator->assertValidOrnParts($service, $provisos, $resource);
        $this->assertThirdPartyClaimHasClient($service, $clientDbId);
        $this->ornValidator->assertClaimParses($service, $provisos, $resource);

        if ($this->claimExists($userDbId, $service, $provisos, $resource)) {
            return;
        }

        Optional::ofNullable($clientDbId)
            ->ifPresent(fn (int $scopedClientDbId) => $this->assertClientClaimCap($userDbId, $scopedClientDbId));

        $this->insertClaim($userDbId, $clientDbId, $updatedByUserDbId, $service, $provisos, $resource);
    }

    public function deleteClaim(int $userDbId, string $service, string $provisos, string $resource): bool
    {
        $this->ornValidator->assertValidOrnParts($service, $provisos, $resource);

        $this->userClaims->clear();
        $this->userClaims->user_id = $userDbId;
        $this->userClaims->service = $service;
        $this->userClaims->provisos = $provisos;
        $this->userClaims->resource = $resource;
        $this->userClaims->delete(null);

        return true;
    }

    private function assertThirdPartyClaimHasClient(string $service, ?int $clientDbId): void
    {
        if ($service === ServiceCatalog::Idp->value) {
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
        $this->userClaims->user_id = $userDbId;
        $this->userClaims->client_id = $clientDbId;
        $count = $this->userClaims->count();

        if ($count >= UserPolicyClaimRepository::MAX_CLAIMS_PER_CLIENT) {
            throw new \InvalidArgumentException(
                sprintf('At most %d policy claims are allowed per user for this client.', UserPolicyClaimRepository::MAX_CLAIMS_PER_CLIENT)
            );
        }
    }
}
