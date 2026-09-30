<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Persistence;

use Amtgard\IdP\Models\Oidc\OidcClaimFactory;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthUser;
use OpenIDConnectServer\Entities\ClaimSetInterface;
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

    public function testImplementsClaimSetInterface(): void
    {
        $oauthUser = OAuthUser::builder()->userEntity($this->user())->build();

        $this->assertInstanceOf(ClaimSetInterface::class, $oauthUser);
    }

    public function testGetClaimsDelegatesToClaimFactory(): void
    {
        $user = $this->user();
        $oauthUser = OAuthUser::builder()
            ->identifier('uuid-1')
            ->userEntity($user)
            ->build();

        $claims = $oauthUser->getClaims();

        $this->assertSame(OidcClaimFactory::fromUser($user), $claims);
        $this->assertSame(['sub' => 'uuid-1'], $claims);
        $this->assertArrayNotHasKey('name', $claims);
        $this->assertArrayNotHasKey('email', $claims);
        $this->assertArrayNotHasKey('email_verified', $claims);
        $this->assertArrayNotHasKey('picture', $claims);
        $this->assertArrayNotHasKey('orkid', $claims);
        $this->assertArrayNotHasKey('policy', $claims);
    }

    public function testGetClaimsIsEmptyWhenUserEntityWasNotRestored(): void
    {
        $oauthUser = unserialize(serialize(
            OAuthUser::builder()->identifier('uuid-1')->userEntity(new UserEntity())->build()
        ));

        $this->assertSame([], $oauthUser->getClaims());
    }

    private function user(): UserEntity
    {
        return new class extends UserEntity {
            public function getUserId(): ?string
            {
                return 'uuid-1';
            }

            public function getEmail(): ?string
            {
                return null;
            }

            public function getFirstName(): ?string
            {
                return '';
            }

            public function getLastName(): ?string
            {
                return '';
            }

            public function getUsername(): ?string
            {
                return null;
            }

            public function getUpdatedAt(): ?\DateTime
            {
                return null;
            }
        };
    }
}
