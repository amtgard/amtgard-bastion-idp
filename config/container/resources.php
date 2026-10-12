<?php

declare(strict_types=1);

use Amtgard\IdP\Persistence\Server\Repositories\UserLoginClientRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserOrkProfileRepository;
use Amtgard\IdP\Persistence\Common\Repositories\UserPolicyClaimRepository;
use Amtgard\IdP\Services\ClientIamMetadataService;
use Amtgard\IdP\Services\ClientIamPolicyService;
use Amtgard\IdP\Services\ResourcesUserinfoService;
use Amtgard\IdP\Utility\Log\IdpLogging;
use Psr\Container\ContainerInterface;

return [
    ResourcesUserinfoService::class => function (ContainerInterface $container) {
        return ResourcesUserinfoService::builder()
            ->orkProfileRepository($container->get(UserOrkProfileRepository::class))
            ->idpLogging($container->get(IdpLogging::class))
            ->build();
    },

    ClientIamPolicyService::class => function (ContainerInterface $container) {
        return ClientIamPolicyService::builder()
            ->policyClaimRepository($container->get(UserPolicyClaimRepository::class))
            ->idpLogging($container->get(IdpLogging::class))
            ->build();
    },

    ClientIamMetadataService::class => function (ContainerInterface $container) {
        return ClientIamMetadataService::builder()
            ->metadataRepository($container->get(UserLoginClientRepository::class))
            ->idpLogging($container->get(IdpLogging::class))
            ->build();
    },
];
