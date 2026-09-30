<?php

declare(strict_types=1);

use Amtgard\IdP\Persistence\Server\Repositories\UserLoginClientRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserOrkProfileRepository;
use Amtgard\IdP\Persistence\Common\Repositories\UserPolicyClaimRepository;
use Amtgard\IdP\Services\ClientIamMetadataService;
use Amtgard\IdP\Services\ClientIamPolicyService;
use Amtgard\IdP\Services\ResourcesUserinfoService;
use Psr\Container\ContainerInterface;

return [
    ResourcesUserinfoService::class => function (ContainerInterface $container) {
        return ResourcesUserinfoService::builder()
            ->orkProfileRepository($container->get(UserOrkProfileRepository::class))
            ->build();
    },

    ClientIamPolicyService::class => function (UserPolicyClaimRepository $policyClaimRepository) {
        return ClientIamPolicyService::builder()
            ->policyClaimRepository($policyClaimRepository)
            ->build();
    },

    ClientIamMetadataService::class => function (UserLoginClientRepository $metadataRepository) {
        return ClientIamMetadataService::builder()
            ->metadataRepository($metadataRepository)
            ->build();
    },
];
