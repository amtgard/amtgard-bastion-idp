<?php

declare(strict_types=1);

/**
 * Fixture mirroring host-env load-env.php: must not redeclare helpers from idp-environment.php.
 */
require_once dirname(__DIR__, 3) . '/config/idp-environment.php';

function idp_load_process_env_fixture(): void
{
    // no-op stand-in for idp_load_process_env
}
