<?php

declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Server\Repositories;

interface AuthCodeNonceLookup
{
    public function findNonceByAuthCodeId(string $authCodeId): ?string;
}
