<?php
declare(strict_types=1);

namespace Amtgard\IdP\Services;

use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Entities\UserLoginEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Utility\Log\IdpLogging;

/**
 * Shared registration flow used by AuthController::register and
 * ConnectController::submitConnectRegister. Both surfaces validate the same
 * input, dedupe against existing users by email, and create a User + Login row.
 */
class RegistrationService
{
    public function __construct(
        private UserRepository $users,
        private UserLoginRepository $logins,
        private IdpLogging $idpLogging,
    ) {}

    /**
     * @return array{ok: true, user: UserEntity, login: UserLoginEntity}
     *         |array{ok: false, error: string}
     */
    public function register(string $firstName, string $lastName, string $email, string $password): array
    {
        if (empty($firstName) || empty($lastName) || empty($email) || empty($password)) {
            $this->idpLogging->security()->error('registration rejected', ['rule' => 'required_fields']);

            return ['ok' => false, 'error' => 'All fields are required'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->idpLogging->security()->error('registration rejected', ['rule' => 'email_format']);

            return ['ok' => false, 'error' => 'Invalid email format'];
        }
        if ($this->users->userExists($email)) {
            $this->idpLogging->security()->error('registration rejected', [
                'rule' => 'email_exists',
                'email_sha256' => hash('sha256', $email),
            ]);

            return ['ok' => false, 'error' => 'Email already registered'];
        }
        $user  = $this->users->createLocalUser($email, $firstName, $lastName);
        $login = $this->logins->createLocalLogin($user, $password);
        $this->idpLogging->security()->info('registration succeeded', ['user_uuid' => $user->getUserId()]);

        return ['ok' => true, 'user' => $user, 'login' => $login];
    }
}
