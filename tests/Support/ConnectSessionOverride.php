<?php

declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Client;

function session_regenerate_id(bool $delete_old_session = false): bool
{
    if (($_ENV['TEST_REGENERATE_FAIL'] ?? '') === '1') {
        return false;
    }

    return \session_regenerate_id($delete_old_session);
}
