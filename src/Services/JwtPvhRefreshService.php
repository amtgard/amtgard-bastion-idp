<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services;

use Amtgard\IdP\Models\AuthorizationJwtAssembler;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Server\Entities\Repository\UserJwtGeneration;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserJwtGenerationRepository;
use Amtgard\IdP\Utility\PvhCacheRecord;
use Optional\Optional;
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
        $this->logger->debug('jwt pvh refresh entry', [
            'user_uuid' => $userUuid,
            'aud' => $aud,
        ]);

        return Optional::ofNullable($this->userRepository->findUserByUserId($userUuid))
            ->map(function ($user) use ($userUuid, $aud): JwtPvhRefreshResult {
                $snapshot = $this->assembler->computePolicyHashForAudience($user, $aud);
                $existing = $this->generationRepository->findByUserUuidAndAud($userUuid, $aud);

                $noop = Optional::ofNullable($existing)
                    ->filter(
                        fn (UserJwtGeneration $row) => hash_equals(
                            $row->getPolicyHash(),
                            $snapshot['policy_hash']
                        )
                    )
                    ->map(function (UserJwtGeneration $row) use ($userUuid, $aud): JwtPvhRefreshResult {
                        $this->logger->notice('jwt pvh refresh noop', [
                            'user_uuid' => $userUuid,
                            'aud' => $aud,
                            'pvh' => $row->getPvh(),
                        ]);

                        return JwtPvhRefreshResult::Noop;
                    });

                if ($noop->isPresent()) {
                    return $noop->get();
                }

                $nowMs = (int) floor(microtime(true) * 1000);
                $row = $this->generationRepository->saveForPolicyHash(
                    (int) $user->id,
                    (string) $user->userId,
                    $snapshot['client_id'],
                    $aud,
                    $snapshot['policy_hash'],
                    $nowMs
                );

                $this->redisCache->setPvhRecord(
                    PvhCacheRecord::fromGeneration($row, (string) ($user->email ?? ''))
                );

                $this->logger->notice('jwt pvh refresh rotated', [
                    'user_uuid' => $userUuid,
                    'aud' => $aud,
                    'pvh' => $row->getPvh(),
                    'prev_pvh' => $row->getPrevPvh(),
                ]);

                return JwtPvhRefreshResult::Rotated;
            })
            ->orElseGet(function () use ($userUuid, $aud): JwtPvhRefreshResult {
                $this->logger->warning('jwt pvh refresh skipped: user not found', [
                    'user_uuid' => $userUuid,
                    'aud' => $aud,
                ]);

                return JwtPvhRefreshResult::UserMissing;
            });
    }
}
