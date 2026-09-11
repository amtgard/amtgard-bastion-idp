<?php

declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Common\Repositories;

use Amtgard\IAM\ClaimFactory;
use Amtgard\IdP\Utility\OrnClaimRegistry;
use Throwable;

final class ClaimOrnValidator
{
    public function assertValidOrnParts(string $service, string $provisos, string $resource): void
    {
        foreach ([['service', $service], ['provisos', $provisos], ['resource', $resource]] as [$label, $value]) {
            if ($value === '') {
                throw new \InvalidArgumentException("$label is required.");
            }
            if (strlen($value) > 50) {
                throw new \InvalidArgumentException("$label must be at most 50 characters.");
            }
        }
    }

    public function assertClaimParses(string $service, string $provisos, string $resource): void
    {
        OrnClaimRegistry::registerForService($service);
        try {
            ClaimFactory::createOrn($service . $provisos . $resource);
        } catch (Throwable $e) {
            throw new \InvalidArgumentException('Invalid ORN claim: ' . $e->getMessage(), 0, $e);
        }
    }
}
