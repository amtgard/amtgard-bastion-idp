<?php

namespace Amtgard\IdP\Models\Orn;

use Amtgard\IAM\Allowance\Claim;

class IdpClaim extends Claim
{

    public function ornSegmentSchema(): array
    {
        return IdpFormat::ornSegmentSchema();
    }

    protected function getResourceMap(string $resource = null): array
    {
        return IdpFormat::getValidResourceMap($resource);
    }
}
