<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Services;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Entities\UserLoginEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Services\RegistrationService;
use PHPUnit\Framework\TestCase;

class RegistrationServiceTest extends TestCase
{
    private UserRepository $users;
    private UserLoginRepository $logins;
    private RegistrationService $service;

    protected function setUp(): void
    {
        $this->users = $this->createMock(UserRepository::class);
        $this->logins = $this->createMock(UserLoginRepository::class);
        $this->service = new RegistrationService(
            $this->createMock(EntityManager::class),
            $this->users,
            $this->logins,
        );
    }

    public function testRegisterRejectsMissingFields(): void
    {
        $result = $this->service->register('', 'Last', 'a@b.com', 'secret');

        $this->assertSame(['ok' => false, 'error' => 'All fields are required'], $result);
    }

    public function testRegisterRejectsInvalidEmail(): void
    {
        $result = $this->service->register('First', 'Last', 'not-an-email', 'secret');

        $this->assertSame(['ok' => false, 'error' => 'Invalid email format'], $result);
    }

    public function testRegisterRejectsExistingEmail(): void
    {
        $this->users->method('userExists')->with('taken@example.com')->willReturn(true);

        $result = $this->service->register('First', 'Last', 'taken@example.com', 'secret');

        $this->assertSame(['ok' => false, 'error' => 'Email already registered'], $result);
    }

    public function testRegisterCreatesUserAndLogin(): void
    {
        $user = $this->createMock(UserEntity::class);
        $login = $this->createMock(UserLoginEntity::class);

        $this->users->method('userExists')->willReturn(false);
        $this->users->expects($this->once())
            ->method('createLocalUser')
            ->with('new@example.com', 'First', 'Last')
            ->willReturn($user);
        $this->logins->expects($this->once())
            ->method('createLocalLogin')
            ->with($user, 'secret')
            ->willReturn($login);

        $result = $this->service->register('First', 'Last', 'new@example.com', 'secret');

        $this->assertTrue($result['ok']);
        $this->assertSame($user, $result['user']);
        $this->assertSame($login, $result['login']);
    }
}
