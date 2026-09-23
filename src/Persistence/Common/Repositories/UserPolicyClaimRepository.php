<?php

declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Common\Repositories;

use Amtgard\ActiveRecordOrm\Configuration\DataAccessPolicy\UncachedDataAccessPolicy;
use Amtgard\ActiveRecordOrm\Entity\EntityMapper;
use Amtgard\ActiveRecordOrm\Factory\TableFactory;
use Amtgard\ActiveRecordOrm\Interface\EntityInterface;
use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\IAM\Allowance\Policy;
use Psr\Log\LoggerInterface;

class UserPolicyClaimRepository
{
    public const MAX_CLAIMS_PER_CLIENT = 25;

    private EntityMapper $userClaims;
    private UserPolicyClaimReader $reader;
    private UserPolicyClaimWriter $writer;
    private LoggerInterface $logger;
    private ClaimOrnValidator $ornValidator;

    public function __construct(
        Database $database,
        UncachedDataAccessPolicy $tablePolicy,
        LoggerInterface $logger,
        ?ClaimOrnValidator $ornValidator = null,
    ) {
        $this->logger = $logger;
        $this->ornValidator = $ornValidator ?? new ClaimOrnValidator();
        $this->useClaimsMapper(
            EntityMapper::builder()
                ->table(TableFactory::build($database, $tablePolicy, 'user_policy_claims'))
                ->build()
        );
    }

    /**
     * @internal test seam — keeps reader/writer on the same mapper instance
     */
    public function useClaimsMapper(EntityMapper $userClaims): void
    {
        $this->userClaims = $userClaims;
        $this->writer = new UserPolicyClaimWriter($userClaims, $this->ornValidator);
        $this->reader = new UserPolicyClaimReader($userClaims, $this->logger);
    }

    /**
     * @return list<array{service: string, provisos: string, resource: string}>
     */
    public function listClaimsForUser(int $userDbId, ?string $service = null, ?int $clientDbId = null): array
    {
        return $this->reader->listClaimsForUser($userDbId, $service, $clientDbId);
    }

    public function addClaim(
        int $userDbId,
        string $service,
        string $provisos,
        string $resource,
        int $updatedByUserDbId,
        ?int $clientDbId = null
    ): void {
        $this->writer->addClaim($userDbId, $service, $provisos, $resource, $updatedByUserDbId, $clientDbId);
    }

    public function deleteClaim(int $userDbId, string $service, string $provisos, string $resource): bool
    {
        return $this->writer->deleteClaim($userDbId, $service, $provisos, $resource);
    }

    public function getUserPolicy(EntityInterface $user, ?int $forClientDbId = null): Policy
    {
        return $this->reader->getUserPolicy($user, $forClientDbId);
    }
}
