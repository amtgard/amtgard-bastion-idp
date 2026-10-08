<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services;

enum JwtPvhRefreshResult: string
{
    case Noop = 'noop';
    case Rotated = 'rotated';
    case UserMissing = 'user_missing';
}
