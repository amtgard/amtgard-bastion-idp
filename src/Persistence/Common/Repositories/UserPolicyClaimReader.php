<?php

declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Common\Repositories;

use Amtgard\ActiveRecordOrm\Entity\EntityMapper;
use Amtgard\ActiveRecordOrm\Entity\Repository\RepositoryEntity;
use Amtgard\ActiveRecordOrm\Interface\EntityInterface;
use Amtgard\IAM\Allowance\Policy;
use Amtgard\IAM\Catalog\ServiceCatalog;
use Amtgard\IAM\ClaimFactory;
use Amtgard\IdP\Utility\BuiltInOrkPolicyServices;
use Amtgard\IdP\Utility\OrnClaimRegistry;
use Psr\Log\LoggerInterface;
use Throwable;

final class UserPolicyClaimReader
{
    public function __construct(
        private EntityMapper $userClaims,
        private LoggerInterface $logger,
    ) {
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

    public function getUserPolicy(EntityInterface $user, ?int $forClientDbId = null): Policy
    {
        $userDbId = $this->resolveUserDbId($user);

        try {
            $this->loadClaimsForUser($userDbId);
            OrnClaimRegistry::registerForService(ServiceCatalog::Idp->value);

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
}
