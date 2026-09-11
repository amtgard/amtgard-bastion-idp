<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services;

use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Common\Repositories\UserPolicyClaimRepository;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Utility\OrnClaimRegistry;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Getter;

final class ClientIamPolicyService
{
    use Builder;
    use Getter;

    protected UserPolicyClaimRepository $policyClaimRepository;

    public function addClaim(
        Client $client,
        UserEntity $user,
        string $provisos,
        string $resource,
    ): void {
        OrnClaimRegistry::registerForClient($client);
        $this->policyClaimRepository->addClaim(
            $user->getId(),
            (string) $client->getIamService(),
            $provisos,
            $resource,
            $user->getId(),
            $client->getId()
        );
    }

    public function deleteClaim(
        Client $client,
        UserEntity $user,
        string $provisos,
        string $resource,
    ): void {
        $this->policyClaimRepository->deleteClaim(
            $user->getId(),
            (string) $client->getIamService(),
            $provisos,
            $resource
        );
    }

    /**
     * @return list<array{service: string, provisos: string, resource: string}>
     */
    public function listClaims(Client $client, UserEntity $user): array
    {
        return $this->policyClaimRepository->listClaimsForUser(
            $user->getId(),
            $client->getIamService(),
            $client->getId()
        );
    }
}
