<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services;

use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Repositories\UserLoginClientRepository;
use Amtgard\IdP\Utility\ClientMetadataValidator;
use Amtgard\IdP\Utility\Log\IdpLogging;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Getter;
use Optional\Optional;
final class ClientIamMetadataService
{
    use Builder;
    use Getter;

    protected UserLoginClientRepository $metadataRepository;

    protected IdpLogging $idpLogging;

    /**
     * @param mixed $metadata
     */
    public function upsert(
        Client $client,
        UserEntity $user,
        int $loginId,
        mixed $metadata,
        ?string $encoding,
    ): void {
        $prepared = ClientMetadataValidator::prepare($metadata, $encoding);
        $this->metadataRepository->upsertMetadata(
            $user->getId(),
            $loginId,
            $client->getId(),
            $prepared['payload'],
            $prepared['encoding']
        );

        $this->idpLogging->audit()->info('client iam metadata upserted', [
            'client_id' => $client->getIdentifier(),
            'login_id' => $loginId,
            'encoding' => $prepared['encoding'],
            'payload_len' => strlen($prepared['payload']),
        ]);
    }

    /**
     * @return array{login_id: int, metadata: mixed, encoding: string}|null
     */
    public function get(Client $client, UserEntity $user, int $loginId): ?array
    {
        $stored = $this->metadataRepository->getMetadata($loginId, $client->getId());
        if ($stored === null) {
            $this->idpLogging->audit()->debug('client iam metadata miss', [
                'client_id' => $client->getIdentifier(),
                'login_id' => $loginId,
            ]);

            return null;
        }

        return [
            'login_id' => $loginId,
            'metadata' => $stored['metadata'],
            'encoding' => $stored['encoding'],
        ];
    }

    public function delete(Client $client, int $loginId): void
    {
        $this->metadataRepository->deleteMetadata($loginId, $client->getId());

        $this->idpLogging->audit()->info('client iam metadata deleted', [
            'client_id' => $client->getIdentifier(),
            'login_id' => $loginId,
        ]);
    }
}
