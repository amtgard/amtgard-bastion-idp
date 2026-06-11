<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

final class LoginSession
{
    public static function setLoginId(int $loginId): void
    {
        $_SESSION['login_id'] = $loginId;
    }

    public static function getLoginId(): ?int
    {
        if (!isset($_SESSION['login_id'])) {
            return null;
        }

        return (int) $_SESSION['login_id'];
    }
}
