<?php

declare(strict_types=1);

use Amtgard\IdP\Services\OrkService;
use Amtgard\IdP\Utility\Http\OrkHttpClient;
use GuzzleHttp\ClientInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

return [
    OrkHttpClient::class => static function (): ClientInterface {
        return OrkHttpClient::createFromEnvironment();
    },

    OrkService::class => function (ContainerInterface $container) {
        return new OrkService(
            $container->get(OrkHttpClient::class),
            $container->get(LoggerInterface::class),
        );
    },
];
