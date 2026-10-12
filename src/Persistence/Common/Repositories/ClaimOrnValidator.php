<?php

declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Common\Repositories;

use Amtgard\IAM\ClaimFactory;
use Amtgard\IdP\Utility\Log\IdpLogging;
use Amtgard\IdP\Utility\OrnClaimRegistry;
use Throwable;

final class ClaimOrnValidator
{
    public function __construct(
        private IdpLogging $idpLogging,
    ) {
    }

    public function assertValidOrnParts(string $service, string $provisos, string $resource): void
    {
        foreach ([['service', $service], ['provisos', $provisos], ['resource', $resource]] as [$label, $value]) {
            if ($value === '') {
                $this->idpLogging->app()->debug('policy claim orn validation failed', [
                    'field' => $label,
                    'rule' => 'required',
                ]);
                throw new \InvalidArgumentException("$label is required.");
            }
            if (strlen($value) > 50) {
                $this->idpLogging->app()->debug('policy claim orn validation failed', [
                    'field' => $label,
                    'rule' => 'max_length',
                ]);
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
            $this->idpLogging->app()->error('policy claim orn parse failed', [
                'field' => 'orn',
                'exception_class' => $e::class,
            ]);
            throw new \InvalidArgumentException('Invalid ORN claim: ' . $e->getMessage(), 0, $e);
        }
    }
}
