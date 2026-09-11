<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility\Security;

use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthUser;
use Amtgard\IdP\Utility\Security\CurrentUserResolver;
use PHPUnit\Framework\TestCase;

final class CurrentUserResolverTest extends TestCase
{
    public function testResolveReturnsNullWhenSessionHasNoUser(): void
    {
        @session_start();
        $_SESSION = [];

        $resolver = CurrentUserResolver::builder()
            ->userRepository($this->createMock(UserRepository::class))
            ->build();

        $this->assertNull($resolver->resolve());
    }

    public function testResolveLoadsUserFromRepository(): void
    {
        @session_start();
        $_SESSION = ['user_id' => 'uuid-1'];

        $userEntity = $this->createMock(UserEntity::class);
        $oauthUser = OAuthUser::builder()->userEntity($userEntity)->build();

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())
            ->method('getUserEntityById')
            ->with('uuid-1')
            ->willReturn($oauthUser);

        $resolver = CurrentUserResolver::builder()->userRepository($users)->build();

        $this->assertSame($userEntity, $resolver->resolve());
    }
}
