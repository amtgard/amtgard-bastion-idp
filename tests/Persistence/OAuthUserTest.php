<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Persistence;

use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthUser;
use PHPUnit\Framework\TestCase;

class OAuthUserTest extends TestCase
{
    public function testAttachedUserEntityReturnsTheBuiltUser(): void
    {
        $user = new UserEntity();
        $oauthUser = OAuthUser::builder()
            ->identifier('uuid-1')
            ->userEntity($user)
            ->build();

        $this->assertSame($user, $oauthUser->attachedUserEntity());
    }

    public function testAttachedUserEntityIsNullAfterSessionSerialization(): void
    {
        $oauthUser = OAuthUser::builder()
            ->identifier('uuid-1')
            ->userEntity(new UserEntity())
            ->build();

        $restored = unserialize(serialize($oauthUser));

        $this->assertInstanceOf(OAuthUser::class, $restored);
        $this->assertSame('uuid-1', $restored->getIdentifier());
        $this->assertNull($restored->attachedUserEntity());
    }
}
