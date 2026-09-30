<?php

declare(strict_types=1);

use Amtgard\IdP\Services\OrkService;
use GuzzleHttp\ClientInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/integ/DevIntegHttpClient.php';

return [
    DevIntegHttpClient::class => static function (ContainerInterface $container): ClientInterface {
        return new DevIntegHttpClient($container->get(LoggerInterface::class));
    },

    OrkService::class => function (ContainerInterface $container) {
        return new OrkService(
            $container->get(DevIntegHttpClient::class),
            $container->get(LoggerInterface::class),
        );
    },
];
