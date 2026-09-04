<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Common\Repositories\UserPolicy;
use Amtgard\IdP\Utility\AppleLoginFeature;
use Amtgard\IdP\Utility\AuthorizedClients;
use Amtgard\IdP\Utility\Constants;
use Amtgard\IdP\Utility\PubSubQueueHandle;
use Amtgard\IdP\Utility\UserAuthority;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UtilityClassesTest extends TestCase
{
    public function testAuthorizedClients(): void
    {
        $clients = AuthorizedClients::builder()
            ->clientIds(['client-a', 'client-b'])
            ->build();

        $this->assertSame(['client-a', 'client-b'], $clients->getClientIds());
    }

    public function testConstants(): void
    {
        $this->assertSame('amtgard-idp-client-id', Constants::$AMTGARD_IDP_CLIENT_ID);
    }

    public function testPubSubQueueHandle(): void
    {
        $handle = PubSubQueueHandle::builder()
            ->handle('my-handle')
            ->build();

        $this->assertSame('my-handle', $handle->getHandle());
    }

    public function testUserAuthorityIsAdmin(): void
    {
        $user = $this->createMock(UserEntity::class);
        $userPolicy = $this->createMock(UserPolicy::class);
        $policyMock = $this->createMock(\Amtgard\IAM\Allowance\Policy::class);

        $userPolicy->expects($this->once())
            ->method('getUserPolicy')
            ->with($user)
            ->willReturn($policyMock);

        $policyMock->expects($this->once())
            ->method('isAuthorized')
            ->with($this->isInstanceOf(\Amtgard\IdP\Models\Orn\IdpRequirement::class))
            ->willReturn(true);

        $authority = new UserAuthority($userPolicy, $this->createMock(LoggerInterface::class));
        $this->assertTrue($authority->isAdmin($user));
    }

    public function testAppleLoginFeatureIsDisabledByDefault(): void
    {
        $previous = $_ENV['APPLE_LOGIN_ENABLED'] ?? null;
        unset($_ENV['APPLE_LOGIN_ENABLED']);

        try {
            $this->assertFalse(AppleLoginFeature::isEnabled());
        } finally {
            if ($previous === null) {
                unset($_ENV['APPLE_LOGIN_ENABLED']);
            } else {
                $_ENV['APPLE_LOGIN_ENABLED'] = $previous;
            }
        }
    }

    public function testAppleLoginFeatureHonorsTruthyEnvValues(): void
    {
        $previous = $_ENV['APPLE_LOGIN_ENABLED'] ?? null;
        $_ENV['APPLE_LOGIN_ENABLED'] = 'true';

        try {
            $this->assertTrue(AppleLoginFeature::isEnabled());
        } finally {
            if ($previous === null) {
                unset($_ENV['APPLE_LOGIN_ENABLED']);
            } else {
                $_ENV['APPLE_LOGIN_ENABLED'] = $previous;
            }
        }
    }
}
