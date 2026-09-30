<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration\Support;

use PDO;

/** Read-only DB helper for integ fixture lookups (host → published MariaDB port). */
final class IntegDb
{
    public static function idpUserUuidForEmail(string $email): string
    {
        $pdo = self::connect();
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $userId = $stmt->fetchColumn();
        if ($userId === false || !is_string($userId) || $userId === '') {
            throw new \RuntimeException("No users.user_id for email {$email}");
        }

        return $userId;
    }

    public static function passwordLoginIdForEmail(string $email): int
    {
        $pdo = self::connect();
        $stmt = $pdo->prepare(
            'SELECT ul.id FROM user_logins ul
             INNER JOIN users u ON u.id = ul.user_id
             WHERE u.email = ?
             ORDER BY ul.id ASC
             LIMIT 1',
        );
        $stmt->execute([$email]);
        $loginId = $stmt->fetchColumn();
        if ($loginId === false) {
            throw new \RuntimeException("No user_logins row for email {$email}");
        }

        return (int) $loginId;
    }

    private static function connect(): PDO
    {
        $host = (string) (getenv('DB_HOST') ?: '127.0.0.1');
        $port = (string) (getenv('DB_PORT') ?: '36307');
        $name = (string) (getenv('DB_NAME') ?: 'idp');
        $user = (string) (getenv('DB_USER') ?: 'idp');
        $pass = (string) (getenv('DB_PASS') ?: 'secret');

        return new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name),
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }
}
