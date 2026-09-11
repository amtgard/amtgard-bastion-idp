<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Pvh;

enum PvhGateOutcome
{
    case Proceed;
    case StaleToken;
    case Unauthorized;
}
