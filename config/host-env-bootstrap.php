<?php

declare(strict_types=1);

use Amtgard\IdP\Utility\Setup\HostEnv\HostEnvBootstrap;

HostEnvBootstrap::builder()->build()->applyFromProcessEnv();
