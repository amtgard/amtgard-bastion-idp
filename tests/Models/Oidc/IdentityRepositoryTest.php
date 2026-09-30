<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Models\Oidc;

use Amtgard\IdP\Models\Oidc\IdentityRepository;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthUser;
use OpenIDConnectServer\Repositories\IdentityProviderInterface;
use PHPUnit\Framework\TestCase;

class IdentityRepositoryTest extends TestCase
{
    public function testImplementsIdentityProviderInterface(): void
    {
        $repository = new IdentityRepository($this->createStub(UserRepository::class));

        $this->assertInstanceOf(IdentityProviderInterface::class, $repository);
    }

    public function testGetUserEntityByIdentifierDelegatesToUserRepository(): void
    {
        $user = OAuthUser::builder()
            ->identifier('uuid-1')
            ->userEntity($this->user())
            ->build();

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())
            ->method('getUserEntityById')
            ->with('uuid-1')
            ->willReturn($user);

        $this->assertSame($user, (new IdentityRepository($users))->getUserEntityByIdentifier('uuid-1'));
    }

    public function testIdentifierIsCastToString(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())
            ->method('getUserEntityById')
            ->with('42')
            ->willReturn(null);

        $this->assertNull((new IdentityRepository($users))->getUserEntityByIdentifier(42));
    }

    public function testMissingUserReturnsNull(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())
            ->method('getUserEntityById')
            ->with('missing')
            ->willReturn(null);

        $this->assertNull((new IdentityRepository($users))->getUserEntityByIdentifier('missing'));
    }

    private function user(): UserEntity
    {
        return new class extends UserEntity {
            public function getUserId(): string
            {
                return 'uuid-1';
            }
        };
    }
}
