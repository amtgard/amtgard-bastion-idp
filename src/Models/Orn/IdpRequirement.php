<?php

namespace Amtgard\IdP\Models\Orn;

use Amtgard\IAM\Requirement\Requirement;

class IdpRequirement extends Requirement
{

    /**
     * @inheritDoc
     */
    public function ornSegmentSchema(): array
    {
        return IdpFormat::ornSegmentSchema();
    }

    protected function getResourceMap(string $resource = null): array
    {
        return IdpFormat::getValidResourceMap($resource);
    }
}
