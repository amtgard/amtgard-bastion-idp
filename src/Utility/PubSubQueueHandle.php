<?php

declare(strict_types=1);


namespace Amtgard\IdP\Utility;

use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Getter;

final class PubSubQueueHandle {
    use Builder, Getter;
    private $handle;
}