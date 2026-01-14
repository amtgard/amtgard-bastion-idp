<?php

namespace Amtgard\IdP\Persistence\Common\Repositories;

use Amtgard\ActiveRecordOrm\Interface\EntityInterface;
use Firebase\JWT\JWT;
use Ramsey\Uuid\Uuid;

class JwtChallenge
{
    public function createChallenge(EntityInterface $user): string {
        return Uuid::uuid4()->toString();
    }

    public function validateChallenge(JWT $jwt): bool {
        return true;
    }
}