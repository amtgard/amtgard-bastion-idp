<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services;

function random_int(int $min, int $max): int
{
    $override = $GLOBALS['idp_mailbox_random_int'] ?? null;
    if (is_callable($override)) {
        return $override($min, $max);
    }

    return \random_int($min, $max);
}
