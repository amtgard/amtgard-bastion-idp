<?php

namespace Amtgard\IdP\Models\Orn;

use Amtgard\IAM\Allowance\Claim;
use Amtgard\IAM\Resource;
use Amtgard\IdP\Utility\ClientApplicationFormatRegistry;

class ClientApplicationClaim extends Claim
{
    public function ornSegmentSchema(): array
    {
        return ClientApplicationFormatRegistry::get($this->getPrefix()->name);
    }

    protected function getResourceMap(string $resource = null): array
    {
        return ClientApplicationFormat::getValidResourceMap($resource);
    }

    protected function validResource(Resource $resource): bool
    {
        return true;
    }
}
