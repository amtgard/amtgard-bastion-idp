<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services;

use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Common\Repositories\UserPolicyClaimRepository;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Utility\Log\IdpLogging;
use Amtgard\IdP\Utility\OrnClaimRegistry;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Getter;

final class ClientIamPolicyService
{
    use Builder;
    use Getter;

    protected UserPolicyClaimRepository $policyClaimRepository;

    protected IdpLogging $idpLogging;

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

        $this->idpLogging->audit()->info('client iam policy claim added', [
            'client_id' => $client->getIdentifier(),
            'user_uuid' => $user->getUserId(),
            'resource' => strlen($resource) > 64 ? substr($resource, 0, 64) . '…' : $resource,
        ]);
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

        $this->idpLogging->audit()->info('client iam policy claim deleted', [
            'client_id' => $client->getIdentifier(),
            'user_uuid' => $user->getUserId(),
        ]);
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
