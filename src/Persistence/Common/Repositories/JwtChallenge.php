<?php

namespace Amtgard\IdP\Persistence\Common\Repositories;

use Amtgard\ActiveRecordOrm\Interface\EntityInterface;
use Ramsey\Uuid\Uuid;

class JwtChallenge
{
    public function createChallenge(EntityInterface $user): string {
        return Uuid::uuid4()->toString();
    }

    public function validateChallenge(string $jwt): bool {
        return true;
    }
}