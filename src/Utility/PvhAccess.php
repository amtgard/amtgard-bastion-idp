<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

enum PvhAccess
{
    case Current;
    case Previous;
    case Unknown;
    case Miss;
}
