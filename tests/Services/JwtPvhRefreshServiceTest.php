<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Services;

use Amtgard\IdP\Models\AuthorizationJwtAssembler;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Common\Repositories\JwtChallenge;
use Amtgard\IdP\Persistence\Common\Repositories\UserPolicy;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Entities\Repository\UserJwtGeneration;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserJwtGenerationRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserLoginClientRepository;
use Amtgard\IdP\Services\JwtPvhRefreshResult;
use Amtgard\IdP\Services\JwtPvhRefreshService;
use Amtgard\IdP\Utility\Pvh;
use Amtgard\IdP\Utility\PvhCacheRecord;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class JwtPvhRefreshServiceTest extends TestCase
{
    private const USER_UUID = 'user-uuid';
    private const AUD = 'client-a';
    private const POLICY_JSON = '["orn:a"]';
    private const METADATA = ['tier' => 'gold'];

    protected function setUp(): void
    {
        unset($_SESSION['login_id'], $_SESSION['client_id']);
    }

    public function testNoopWhenPolicyHashMatchesDoesNotWriteRedis(): void
    {
        $hash = $this->canonicalHash();
        $existing = $this->generation(policyHash: $hash);

        $generationRepository = $this->createMock(UserJwtGenerationRepository::class);
        $generationRepository->expects($this->once())
            ->method('findByUserUuidAndAud')
            ->with(self::USER_UUID, self::AUD)
            ->willReturn($existing);
        $generationRepository->expects($this->never())->method('saveForPolicyHash');

        $redis = $this->createMock(RedisCacheRepository::class);
        $redis->expects($this->never())->method('setPvhRecord');

        $result = $this->service($generationRepository, $redis)->refresh(self::USER_UUID, self::AUD);

        $this->assertSame(JwtPvhRefreshResult::Noop, $result);
    }

    public function testRotateWhenPolicyHashDiffersWritesPrevPvhToRedis(): void
    {
        $oldHash = Pvh::policyHash(self::AUD, '["orn:old"]', '');
        $newHash = $this->canonicalHash();
        $oldPvh = str_repeat('a', 44);
        $newPvh = Pvh::encode(1_800_000_000_000, $newHash);

        $existing = $this->generation(policyHash: $oldHash);
        $rotated = $this->generation(
            policyHash: $newHash,
            pvh: $newPvh,
            prevPvh: $oldPvh,
        );

        $generationRepository = $this->createMock(UserJwtGenerationRepository::class);
        $generationRepository->expects($this->once())
            ->method('findByUserUuidAndAud')
            ->with(self::USER_UUID, self::AUD)
            ->willReturn($existing);
        $generationRepository->expects($this->once())
            ->method('saveForPolicyHash')
            ->with(
                7,
                self::USER_UUID,
                99,
                self::AUD,
                $newHash,
                $this->isType('int')
            )
            ->willReturn($rotated);

        $redis = $this->createMock(RedisCacheRepository::class);
        $redis->expects($this->once())
            ->method('setPvhRecord')
            ->with($this->callback(function (PvhCacheRecord $record) use ($newPvh, $oldPvh): bool {
                return $record->getUserUuid() === self::USER_UUID
                    && $record->getAud() === self::AUD
                    && $record->getEmail() === 'user@example.com'
                    && $record->getPvh() === $newPvh
                    && $record->getPrevPvh() === $oldPvh;
            }));

        $result = $this->service($generationRepository, $redis)->refresh(self::USER_UUID, self::AUD);

        $this->assertSame(JwtPvhRefreshResult::Rotated, $result);
    }

    public function testMissingUserDoesNotWriteRedis(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects($this->once())
            ->method('findUserByUserId')
            ->with(self::USER_UUID)
            ->willReturn(null);

        $generationRepository = $this->createMock(UserJwtGenerationRepository::class);
        $generationRepository->expects($this->never())->method('findByUserUuidAndAud');
        $generationRepository->expects($this->never())->method('saveForPolicyHash');

        $redis = $this->createMock(RedisCacheRepository::class);
        $redis->expects($this->never())->method('setPvhRecord');

        $service = new JwtPvhRefreshService(
            $userRepository,
            $this->assembler(),
            $generationRepository,
            $redis,
            $this->createMock(LoggerInterface::class),
        );

        $this->assertSame(
            JwtPvhRefreshResult::UserMissing,
            $service->refresh(self::USER_UUID, self::AUD)
        );
    }

    private function generation(
        string $policyHash,
        string $pvh = 'current-pvh-placeholder-44-chars-xxxxxxxx',
        ?string $prevPvh = null,
    ): UserJwtGeneration {
        $row = (new \ReflectionClass(UserJwtGeneration::class))->newInstanceWithoutConstructor();
        $userUuid = self::USER_UUID;
        $aud = self::AUD;
        \Closure::bind(function () use ($userUuid, $aud, $policyHash, $pvh, $prevPvh): void {
            $this->userUuid = $userUuid;
            $this->aud = $aud;
            $this->pvh = $pvh;
            $this->prevPvh = $prevPvh;
            $this->policyHash = $policyHash;
        }, $row, UserJwtGeneration::class)();

        return $row;
    }

    private function canonicalHash(): string
    {
        return Pvh::policyHash(
            self::AUD,
            self::POLICY_JSON,
            Pvh::canonicalMetadata(self::METADATA)
        );
    }

    private function service(
        UserJwtGenerationRepository $generationRepository,
        RedisCacheRepository $redis
    ): JwtPvhRefreshService {
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findUserByUserId')->with(self::USER_UUID)->willReturn($this->user());

        return new JwtPvhRefreshService(
            $userRepository,
            $this->assembler(),
            $generationRepository,
            $redis,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function assembler(): AuthorizationJwtAssembler
    {
        $userPolicy = $this->createMock(UserPolicy::class);
        $userPolicy->method('toPolicyJson')->willReturn(self::POLICY_JSON);

        $client = new class extends Client {
            public function getId(): int
            {
                return 99;
            }
        };
        $clientRepository = $this->createMock(ClientRepository::class);
        $clientRepository->method('findClientByIdentifier')->with(self::AUD)->willReturn($client);

        $metadataRepository = $this->createMock(UserLoginClientRepository::class);
        $metadataRepository->method('getMetadataForJwt')->with(55, 99)->willReturn(self::METADATA);

        $userLoginRepository = $this->createMock(UserLoginRepository::class);
        $userLoginRepository->method('resolveDefaultLoginIdForUser')->with(7)->willReturn(55);

        return new AuthorizationJwtAssembler(
            $userPolicy,
            $this->createMock(JwtChallenge::class),
            $clientRepository,
            $metadataRepository,
            $userLoginRepository,
            $this->createMock(LoggerInterface::class),
            $this->createMock(UserJwtGenerationRepository::class),
        );
    }

    private function user(): UserEntity
    {
        $user = (new \ReflectionClass(UserEntity::class))->newInstanceWithoutConstructor();
        foreach (['id' => 7, 'userId' => self::USER_UUID, 'email' => 'user@example.com'] as $prop => $value) {
            $ref = new \ReflectionProperty(UserEntity::class, $prop);
            $ref->setValue($user, $value);
        }

        return $user;
    }
}
