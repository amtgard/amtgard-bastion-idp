<?php

namespace Amtgard\IdP\Models\Orn;

use Amtgard\IAM\Requirement\Requirement;

class IdpRequirement extends Requirement
{

    /**
     * @inheritDoc
     */
    protected function serviceFormat(): array
    {
        return IdpFormat::serviceFormat();
    }

    protected function getResourceMap(string $resource = null): array
    {
        return IdpFormat::getValidResourceMap($resource);
    }
}