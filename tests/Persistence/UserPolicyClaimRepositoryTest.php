<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Persistence;

use Amtgard\ActiveRecordOrm\Configuration\DataAccessPolicy\UncachedDataAccessPolicy;
use Amtgard\ActiveRecordOrm\Entity\EntityMapper;
use Amtgard\ActiveRecordOrm\Interface\EntityInterface;
use Amtgard\IAM\OrkServices;
use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\IdP\Persistence\Common\Repositories\UserPolicyClaimRepository;
use Amtgard\IdP\Utility\ClientApplicationFormatRegistry;
use PHPUnit\Framework\TestCase;

class UserPolicyClaimRepositoryCursorMapper extends EntityMapper
{
    public array $assigned = [];
    private int $index = -1;

    public function __construct(private array $rows) {}

    public function __get(string $name)
    {
        return $this->rows[$this->index][$name] ?? null;
    }

    public function __set(string $name, $value): void
    {
        $this->assigned[$name] = $value;
    }

    public function clear(): void {}
    public function find(): int { return count($this->rows); }
    public function next(): bool
    {
        $this->index++;
        return isset($this->rows[$this->index]);
    }
}

class UserPolicyClaimRepositoryTest extends TestCase
{
    private EntityMapper $mapper;
    private UserPolicyClaimRepository $repository;

    protected function setUp(): void
    {
        ClientApplicationFormatRegistry::register('Skbc', [
            \Amtgard\IAM\OrkServices::Configuration,
            \Amtgard\IAM\OrkServices::Game,
            \Amtgard\IAM\OrkServices::Kingdom,
            \Amtgard\IAM\OrkServices::Park,
        ]);

        $this->mapper = $this->createMock(EntityMapper::class);
        $this->repository = new UserPolicyClaimRepository(
            $this->createMock(Database::class),
            $this->createMock(UncachedDataAccessPolicy::class),
        );

        $property = new \ReflectionProperty(UserPolicyClaimRepository::class, 'userClaims');
        $property->setAccessible(true);
        $property->setValue($this->repository, $this->mapper);
    }

    protected function tearDown(): void
    {
        ClientApplicationFormatRegistry::reset();
    }

    public function testAddClaimRejectsEmptyProvisos(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repository->addClaim(1, 'Skbc', '', 'Officer/Approve', 1, 5);
    }

    public function testAddClaimRequiresClientForThirdPartyService(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repository->addClaim(1, 'Skbc', ':0::::', 'Officer/Approve', 1, null);
    }

    public function testAddClaimIsIdempotentWhenClaimAlreadyExists(): void
    {
        $this->mapper->method('find')->willReturn(1);

        $this->mapper->expects($this->never())->method('execute');

        $this->repository->addClaim(1, 'Skbc', ':0::::', 'Officer/Approve', 1, 5);
    }

    public function testDeleteClaimExecutesDeleteStatement(): void
    {
        $this->mapper->expects($this->once())->method('clear');
        $this->mapper->expects($this->once())->method('query');
        $this->mapper->expects($this->once())->method('execute');

        $this->assertTrue($this->repository->deleteClaim(1, 'Skbc', ':0::::', 'Officer/Approve'));
    }

    public function testAddClaimInsertsWhenClaimDoesNotExist(): void
    {
        $this->mapper->method('find')->willReturn(0);
        $this->mapper->method('next')->willReturn(false);
        $this->mapper->expects($this->atLeastOnce())->method('execute');

        $this->repository->addClaim(1, 'Skbc', ':0::::', 'Officer/Approve', 1, 5);
    }

    public function testListClaimsForUserReturnsOnlyRowsMatchingFilters(): void
    {
        $mapper = new UserPolicyClaimRepositoryCursorMapper([
            ['service' => 'Skbc', 'client_id' => 5, 'provisos' => ':0::::', 'resource' => 'Officer/Approve'],
            ['service' => 'Skbc', 'client_id' => 6, 'provisos' => ':0::::', 'resource' => 'Officer/Deny'],
            ['service' => OrkServices::Idp->value, 'client_id' => null, 'provisos' => ':0::::', 'resource' => 'IDP/EditClient'],
        ]);
        $this->replaceMapper($mapper);

        $claims = $this->repository->listClaimsForUser(10, 'Skbc', 5);

        $this->assertSame([
            ['service' => 'Skbc', 'provisos' => ':0::::', 'resource' => 'Officer/Approve'],
        ], $claims);
        $this->assertSame(10, $mapper->assigned['user_id']);
    }

    public function testGetUserPolicyIncludesBuiltInOrkClaimsForAnyIntegrator(): void
    {
        $mapper = new UserPolicyClaimRepositoryCursorMapper([
            ['service' => OrkServices::ORK->value, 'client_id' => 99, 'provisos' => ':0:::::', 'resource' => 'ORK/AddKingdom'],
            ['service' => 'Skbc', 'client_id' => 5, 'provisos' => ':0::::', 'resource' => 'Officer/Approve'],
            ['service' => 'Skbc', 'client_id' => 6, 'provisos' => ':0::::', 'resource' => 'Officer/Deny'],
        ]);
        $this->replaceMapper($mapper);

        $user = $this->createMock(EntityInterface::class);
        $user->id = 10;

        $encodedPolicy = $this->repository->getUserPolicy($user, 5)->toJson();
        $claims = json_decode($encodedPolicy, true, flags: JSON_THROW_ON_ERROR);

        $this->assertContains('ORK:0:::::ORK/AddKingdom', $claims);
        $this->assertContains('Skbc:0::::Officer/Approve', $claims);
        $this->assertNotContains('Skbc:0::::Officer/Deny', $claims);
    }

    public function testGetUserPolicyIncludesIdpAndMatchingClientClaims(): void
    {
        $mapper = new UserPolicyClaimRepositoryCursorMapper([
            ['service' => OrkServices::Idp->value, 'client_id' => null, 'provisos' => ':0::::', 'resource' => 'IDP/EditClient'],
            ['service' => 'Skbc', 'client_id' => 5, 'provisos' => ':0::::', 'resource' => 'Officer/Approve'],
            ['service' => 'Skbc', 'client_id' => 6, 'provisos' => ':0::::', 'resource' => 'Officer/Deny'],
        ]);
        $this->replaceMapper($mapper);

        $user = $this->createMock(EntityInterface::class);
        $user->id = 10;

        $encodedPolicy = $this->repository->getUserPolicy($user, 5)->toJson();
        $claims = json_decode($encodedPolicy, true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsString($encodedPolicy);
        $this->assertContains('Idp:0::::IDP/EditClient', $claims);
        $this->assertContains('Skbc:0::::Officer/Approve', $claims);
        $this->assertNotContains('Skbc:0::::Officer/Deny', $claims);
    }

    public function testAddClaimRejectsInvalidOrnForCustomFormat(): void
    {
        ClientApplicationFormatRegistry::register('Skbc', ['tenant-id']);

        $this->expectException(\InvalidArgumentException::class);
        $this->repository->addClaim(1, 'Skbc', ':0::', 'Officer/Approve', 1, 5);
    }

    private function replaceMapper(EntityMapper $mapper): void
    {
        $this->mapper = $mapper;
        $property = new \ReflectionProperty(UserPolicyClaimRepository::class, 'userClaims');
        $property->setAccessible(true);
        $property->setValue($this->repository, $mapper);
    }
}
