<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services;

use Amtgard\IdP\Models\AuthorizationJwtAssembler;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserJwtGenerationRepository;
use Amtgard\IdP\Utility\PvhCacheRecord;
use Psr\Log\LoggerInterface;

enum JwtPvhRefreshResult: string
{
    case Noop = 'noop';
    case Rotated = 'rotated';
    case UserMissing = 'user_missing';
}

/**
 * Worker body: recompute canonical policy_hash for (user_uuid, aud) and
 * rotate MySQL + Redis pvh only when the hash changed. Does not mint or sign a JWT.
 */
final class JwtPvhRefreshService
{
    public function __construct(
        private UserRepository $userRepository,
        private AuthorizationJwtAssembler $assembler,
        private UserJwtGenerationRepository $generationRepository,
        private RedisCacheRepository $redisCache,
        private LoggerInterface $logger,
    ) {
    }

    public function refresh(string $userUuid, string $aud): JwtPvhRefreshResult
    {
        $user = $this->userRepository->findUserByUserId($userUuid);
        if ($user === null) {
            $this->logger->warning('jwt pvh refresh skipped: user not found', [
                'user_uuid' => $userUuid,
                'aud' => $aud,
            ]);

            return JwtPvhRefreshResult::UserMissing;
        }

        $snapshot = $this->assembler->computePolicyHashForAudience($user, $aud);
        $existing = $this->generationRepository->findByUserUuidAndAud($userUuid, $aud);

        if ($existing !== null && hash_equals($existing->getPolicyHash(), $snapshot['policy_hash'])) {
            $this->logger->notice('jwt pvh refresh noop', [
                'user_uuid' => $userUuid,
                'aud' => $aud,
                'pvh' => $existing->getPvh(),
            ]);

            return JwtPvhRefreshResult::Noop;
        }

        $nowMs = (int) floor(microtime(true) * 1000);
        $row = $this->generationRepository->upsert(
            (int) $user->id,
            (string) $user->userId,
            $snapshot['client_id'],
            $aud,
            $snapshot['policy_hash'],
            $nowMs
        );

        $this->redisCache->setPvhRecord(new PvhCacheRecord(
            $row->getUserUuid(),
            $row->getAud(),
            (string) ($user->email ?? ''),
            $row->getPvh(),
            $row->getPrevPvh(),
        ));

        $this->logger->notice('jwt pvh refresh rotated', [
            'user_uuid' => $userUuid,
            'aud' => $aud,
            'pvh' => $row->getPvh(),
            'prev_pvh' => $row->getPrevPvh(),
        ]);

        return JwtPvhRefreshResult::Rotated;
    }
}
