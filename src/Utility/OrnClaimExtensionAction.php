<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

enum OrnClaimExtensionAction
{
    case Noop;
    case Register;
}
