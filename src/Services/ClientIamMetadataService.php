<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services;

use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Repositories\UserLoginClientRepository;
use Amtgard\IdP\Utility\ClientMetadataValidator;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Getter;
use Optional\Optional;
final class ClientIamMetadataService
{
    use Builder;
    use Getter;

    protected UserLoginClientRepository $metadataRepository;

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
    }

    /**
     * @return array{login_id: int, metadata: mixed, encoding: string}|null
     */
    public function get(Client $client, UserEntity $user, int $loginId): ?array
    {
        return Optional::ofNullable($this->metadataRepository->getMetadata($loginId, $client->getId()))
            ->map(fn (array $stored): array => [
                'login_id' => $loginId,
                'metadata' => $stored['metadata'],
                'encoding' => $stored['encoding'],
            ])
            ->orElse(null);
    }

    public function delete(Client $client, int $loginId): void
    {
        $this->metadataRepository->deleteMetadata($loginId, $client->getId());
    }
}
