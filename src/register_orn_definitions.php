<?php

declare(strict_types=1);

use Amtgard\IAM\ORN\OrnClassMap;
use Amtgard\IAM\OrkServices;
use Amtgard\IdP\Models\Orn\IdpClaim;
use Amtgard\IdP\Models\Orn\IdpRequirement;

OrnClassMap::registerClaim(OrkServices::Idp, IdpClaim::class);
OrnClassMap::registerRequirement(OrkServices::Idp, IdpRequirement::class);
