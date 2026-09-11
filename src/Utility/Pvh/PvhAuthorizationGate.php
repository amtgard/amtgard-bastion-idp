<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Pvh;

use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Utility\Jwt;
use Amtgard\IdP\Utility\PvhAccess;
use Amtgard\IdP\Utility\PvhCacheRecord;
use Amtgard\IdP\Utility\PvhGate;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Getter;
use Psr\Log\LoggerInterface;

final class PvhAuthorizationGate
{
    use Builder;
    use Getter;

    protected RedisCacheRepository $redisCacheRepository;

    protected ?LoggerInterface $logger = null;

    protected ?PvhAccess $lastAccess = null;

    protected ?PvhCacheRecord $lastCachedRecord = null;

    protected ?PvhCacheRecord $lastResolvedRecord = null;

    public function evaluateAndSeed(string $userUuid, string $aud, array $payload): PvhGateOutcome
    {
        $this->lastAccess = null;
        $this->lastCachedRecord = null;
        $this->lastResolvedRecord = null;

        $cached = $this->redisCacheRepository->getPvhRecord($userUuid, $aud);
        $this->lastCachedRecord = $cached;
        $access = PvhGate::evaluate($cached, $payload);
        $this->lastAccess = $access;

        $this->logDebug($userUuid, $aud, $access);

        return match ($access) {
            PvhAccess::Current => $this->proceedFromCurrent($cached),
            PvhAccess::Previous => PvhGateOutcome::StaleToken,
            PvhAccess::Miss => $this->proceedFromMiss($userUuid, $aud, $payload),
            PvhAccess::Unknown => PvhGateOutcome::Unauthorized,
        };
    }

    public function lastAccess(): ?PvhAccess
    {
        return $this->lastAccess;
    }

    public function lastCachedRecord(): ?PvhCacheRecord
    {
        return $this->lastCachedRecord;
    }

    public function lastResolvedRecord(): ?PvhCacheRecord
    {
        return $this->lastResolvedRecord;
    }

    protected function proceedFromCurrent(?PvhCacheRecord $cached): PvhGateOutcome
    {
        $this->lastResolvedRecord = $cached;

        return PvhGateOutcome::Proceed;
    }

    protected function proceedFromMiss(string $userUuid, string $aud, array $payload): PvhGateOutcome
    {
        $pvhContext = Jwt::presentedPvhContext($payload);
        $seeded = PvhGate::missSeedRecord(
            $userUuid,
            $aud,
            Jwt::emailClaim($payload),
            $pvhContext['presented'],
            $pvhContext['fatPolicyHash']
        );
        $this->redisCacheRepository->setPvhRecord($seeded);
        $this->lastResolvedRecord = $seeded;

        return PvhGateOutcome::Proceed;
    }

    protected function logDebug(string $userUuid, string $aud, PvhAccess $access): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->debug(
            'pvh authorization gate',
            [
                'user_uuid' => $userUuid,
                'aud' => $aud,
                'access' => $access->name,
            ]
        );
    }
}
