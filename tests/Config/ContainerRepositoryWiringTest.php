<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Config;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Persistence\Server\Repositories\UserJwtGenerationRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserLoginClientRepository;
use PHPUnit\Framework\TestCase;

/**
 * Mirrors config/container.php ORM repository wiring.
 */
class ContainerRepositoryWiringTest extends TestCase
{
    public function testContainerDefinesUserLoginClientRepositoryWiring(): void
    {
        $definitions = require __DIR__ . '/../../config/container.php';

        $this->assertArrayHasKey(UserLoginClientRepository::class, $definitions);
    }

    public function testUserLoginClientRepositoryIsResolvedFromEntityManager(): void
    {
        $repository = $this->createStub(UserLoginClientRepository::class);
        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->expects($this->once())
            ->method('getRepository')
            ->with(UserLoginClientRepository::class)
            ->willReturn($repository);

        $definitions = require __DIR__ . '/../../config/container.php';
        $resolved = $definitions[UserLoginClientRepository::class]($entityManager);

        $this->assertSame($repository, $resolved);
    }

    public function testUninitializedRepositoryFailsOnMetadataLookup(): void
    {
        $repository = UserLoginClientRepository::builder()->build();

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('$entityMapper must not be accessed before initialization');

        $repository->getMetadataForJwt(1, 2);
    }

    public function testContainerDefinesUserJwtGenerationRepositoryWiring(): void
    {
        $definitions = require __DIR__ . '/../../config/container.php';

        $this->assertArrayHasKey(UserJwtGenerationRepository::class, $definitions);
    }

    public function testUserJwtGenerationRepositoryIsResolvedFromEntityManager(): void
    {
        $repository = $this->createStub(UserJwtGenerationRepository::class);
        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->expects($this->once())
            ->method('getRepository')
            ->with(UserJwtGenerationRepository::class)
            ->willReturn($repository);

        $definitions = require __DIR__ . '/../../config/container.php';
        $resolved = $definitions[UserJwtGenerationRepository::class]($entityManager);

        $this->assertSame($repository, $resolved);
    }
}
