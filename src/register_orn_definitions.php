<?php

declare(strict_types=1);

use Amtgard\IAM\ORN\OrnClassMap;
use Amtgard\IAM\Catalog\ServiceCatalog;
use Amtgard\IdP\Models\Orn\IdpClaim;
use Amtgard\IdP\Models\Orn\IdpRequirement;

OrnClassMap::registerClaim(ServiceCatalog::Idp, IdpClaim::class);
OrnClassMap::registerRequirement(ServiceCatalog::Idp, IdpRequirement::class);
