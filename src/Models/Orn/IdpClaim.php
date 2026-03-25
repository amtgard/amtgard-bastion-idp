<?php

namespace Amtgard\IdP\Models\Orn;

use Amtgard\IAM\Allowance\Claim;

class IdpClaim extends Claim
{

    protected function serviceFormat(): array
    {
        return IdpFormat::serviceFormat();
    }

    protected function getResourceMap(string $resource = null): array
    {
        return IdpFormat::getValidResourceMap($resource);
    }
}