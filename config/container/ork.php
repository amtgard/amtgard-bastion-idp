<?php

declare(strict_types=1);

use Amtgard\IdP\Services\OrkService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

return [
    OrkService::class => function (ContainerInterface $container) {
        return new OrkService($container->get(LoggerInterface::class));
    },
];
