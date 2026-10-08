<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services\Ork;

use Amtgard\IdP\Persistence\Client\Repositories\OrkClaimPendingRepository;
use Amtgard\IdP\Persistence\Client\Repositories\OrkClaimSendNonceRepository;
use DateTimeImmutable;

final class OrkClaimSendNonceService
{
    private const TTL_SECONDS = 900;

    public function __construct(
        private OrkClaimSendNonceRepository $sendNonces,
        private OrkClaimPendingRepository $pendingClaims,
    ) {}

    /**
     * @return array{send_nonce: string, mundane_id: int}
     */
    public function issueForClaim(string $idpUserId, int $mundaneId, string $username): array
    {
        $nonce = bin2hex(random_bytes(32));
        $nonceHash = hash('sha256', $nonce);
        $expires = new DateTimeImmutable('+' . self::TTL_SECONDS . ' seconds');

        $this->sendNonces->insert($nonceHash, $idpUserId, $mundaneId, $expires);
        $this->pendingClaims->replaceOpen($idpUserId, $mundaneId, $username, $expires);

        return [
            'send_nonce' => $nonce,
            'mundane_id' => $mundaneId,
        ];
    }

    /**
     * @return array{idp_user_id: string, mundane_id: int}|null
     */
    public function validateAndConsumeSendNonce(string $sendNonce): ?array
    {
        $sendNonce = trim($sendNonce);
        if ($sendNonce === '') {
            return null;
        }

        $nonceHash = hash('sha256', $sendNonce);

        return $this->sendNonces->consumeValid($nonceHash, new DateTimeImmutable());
    }

    /**
     * @return array{id: string, mundane_id: int, username: string}|null
     */
    public function findOpenPendingClaim(string $idpUserId): ?array
    {
        return $this->pendingClaims->findOpenByIdpUserId($idpUserId, new DateTimeImmutable());
    }

    public function completePendingClaim(string $pendingId): void
    {
        $this->pendingClaims->complete($pendingId);
    }
}
