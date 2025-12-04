<?php

namespace Amtgard\IdP\AuthClient\Repositories;

use Amtgard\ActiveRecordOrm\Configuration\DataAccessPolicy\UncachedDataAccessPolicy;
use Amtgard\ActiveRecordOrm\Entity\Entity;
use Amtgard\ActiveRecordOrm\Entity\EntityMapper;
use Amtgard\ActiveRecordOrm\Interface\EntityInterface;
use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\ActiveRecordOrm\TableFactory;
use Amtgard\IAM\Allowance\Policy;
use Amtgard\IAM\ClaimFactory;

class UserPolicy
{
    private EntityMapper $userClaims;
    public function __construct(Database $database, UncachedDataAccessPolicy $tablePolicy) {
        $this->userClaims = EntityMapper::builder()
            ->table(TableFactory::build($database, $tablePolicy, 'user_policy_claims'))
            ->build();
    }

    public function getUserPolicy(EntityInterface $user): Policy {
        $this->userClaims->clear();
        $this->userClaims->user_id = $user->userId;
        $policyClaims = [];
        $this->userClaims->find();
        while ($this->userClaims->next()) {
            $orn = $this->userClaims->service . $this->userClaims->provisos . $this->userClaims->resource;
            $policyClaims[] = ClaimFactory::createOrn($orn);
        }
        return new Policy($policyClaims);
    }

}