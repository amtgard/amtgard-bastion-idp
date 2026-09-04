<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

use Amtgard\SetQueue\DataStructure\SetQueue;

/**
 * Distinct SetQueue type for REDIS_PVH_QUEUE_NAME so PHP-DI can bind it
 * separately from the presence SetQueue::class.
 */
class PvhSetQueue extends SetQueue
{
}
