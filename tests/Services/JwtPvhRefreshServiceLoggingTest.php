<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Services;

use Amtgard\IdP\Models\AuthorizationJwtAssembler;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Server\Entities\Repository\UserJwtGeneration;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserJwtGenerationRepository;
use Amtgard\IdP\Services\JwtPvhRefreshResult;
use Amtgard\IdP\Services\JwtPvhRefreshService;
use Amtgard\IdP\Tests\Support\CapturedIdpLogging;
use Amtgard\IdP\Utility\Pvh;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class JwtPvhRefreshServiceLoggingTest extends TestCase
{
    private const USER_UUID = 'user-uuid';
    private const AUD = 'client-a';

    public function testLogsInfoNoopWithoutPvhField(): void
    {
        $capture = CapturedIdpLogging::create();
        $hash = Pvh::policyHash(self::AUD, '[]', Pvh::canonicalMetadata(['tier' => 'gold']));
        $existing = $this->generation($hash);

        $generationRepository = $this->createMock(UserJwtGenerationRepository::class);
        $generationRepository->method('findByUserUuidAndAud')->willReturn($existing);

        $this->service($capture, $generationRepository, $this->createStub(RedisCacheRepository::class))
            ->refresh(self::USER_UUID, self::AUD);

        $record = $this->findRecord($capture, 'jwt pvh refresh noop');
        $this->assertSame(Level::Info, $record->level);
        $this->assertArrayNotHasKey('pvh', $record->context);
    }

    public function testLogsWarningWhenUserMissing(): void
    {
        $capture = CapturedIdpLogging::create();
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findUserByUserId')->willReturn(null);

        $service = new JwtPvhRefreshService(
            $userRepository,
            $this->createStub(AuthorizationJwtAssembler::class),
            $this->createStub(UserJwtGenerationRepository::class),
            $this->createStub(RedisCacheRepository::class),
            $capture->idpLogging,
        );

        $this->assertSame(
            JwtPvhRefreshResult::UserMissing,
            $service->refresh(self::USER_UUID, self::AUD)
        );

        $record = $this->findRecord($capture, 'jwt pvh refresh skipped: user not found');
        $this->assertSame(Level::Warning, $record->level);
    }

    private function service(
        CapturedIdpLogging $capture,
        UserJwtGenerationRepository $generationRepository,
        RedisCacheRepository $redis,
    ): JwtPvhRefreshService {
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findUserByUserId')->willReturn($this->user());

        $assembler = $this->createMock(AuthorizationJwtAssembler::class);
        $assembler->method('computePolicyHashForAudience')->willReturn([
            'policy_hash' => Pvh::policyHash(self::AUD, '[]', Pvh::canonicalMetadata(['tier' => 'gold'])),
            'client_id' => 99,
        ]);

        return new JwtPvhRefreshService(
            $userRepository,
            $assembler,
            $generationRepository,
            $redis,
            $capture->idpLogging,
        );
    }

    private function generation(string $policyHash): UserJwtGeneration
    {
        $row = (new \ReflectionClass(UserJwtGeneration::class))->newInstanceWithoutConstructor();
        \Closure::bind(function () use ($policyHash): void {
            $this->policyHash = $policyHash;
        }, $row, UserJwtGeneration::class)();

        return $row;
    }

    private function user(): UserEntity
    {
        $user = (new \ReflectionClass(UserEntity::class))->newInstanceWithoutConstructor();
        foreach (['id' => 7, 'userId' => self::USER_UUID, 'email' => 'user@example.com'] as $prop => $value) {
            (new \ReflectionProperty(UserEntity::class, $prop))->setValue($user, $value);
        }

        return $user;
    }

    private function findRecord(CapturedIdpLogging $capture, string $message): LogRecord
    {
        foreach ($capture->handler('pvh')->getRecords() as $record) {
            if ($record->message === $message) {
                return $record;
            }
        }

        $this->fail('Missing log record: ' . $message);
    }
}
