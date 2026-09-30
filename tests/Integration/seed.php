<?php

declare(strict_types=1);

use Amtgard\IAM\Catalog\ServiceCatalog;
use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Services\RegistrationService;
use Amtgard\IdP\Tests\Integration\IntegFixtures;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

integApplyCliEnvironment();

$container = require dirname(__DIR__, 2) . '/config/bootstrap.php';
integApplyCliEnvironment();

if (($_ENV['ENVIRONMENT'] ?? '') !== 'DEV_INTEG') {
    fwrite(STDERR, "seed.php requires ENVIRONMENT=DEV_INTEG\n");
    exit(1);
}

$pdo = integPdo();
purgeFixtures($pdo);

/** @var RegistrationService $registration */
$registration = $container->get(RegistrationService::class);

$registration->register('Integ', 'Player', IntegFixtures::PLAYER_EMAIL, IntegFixtures::PASSWORD);
$admin = $registration->register('Integ', 'Admin', IntegFixtures::ADMIN_EMAIL, IntegFixtures::PASSWORD);

seedAdminClaim(
    $pdo,
    (int) $admin['user']->getId(),
    (int) $admin['user']->getId(),
);

$client = Client::builder()
    ->identifier(IntegFixtures::CONFIDENTIAL_CLIENT_ID)
    ->clientSecret(IntegFixtures::CONFIDENTIAL_CLIENT_SECRET)
    ->name('Integration confidential client')
    ->redirectUri(IntegFixtures::CONFIDENTIAL_REDIRECT_URI)
    ->isConfidential(true)
    ->isDev(true)
    ->iamService('IntegApp')
    ->iamServiceFormat(null)
    ->build();

EntityManager::getManager()->persist($client);

fwrite(STDOUT, "Integ fixtures seeded (schema {$_ENV['DB_NAME']}).\n");

function integApplyCliEnvironment(): void
{
    foreach (['ENVIRONMENT' => 'DEV_INTEG', 'DB_NAME' => 'idp_integ'] as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function integPdo(): PDO
{
    $host = (string) ($_ENV['DB_HOST'] ?? 'localhost');
    $port = (string) ($_ENV['DB_PORT'] ?? '3306');
    $name = (string) ($_ENV['DB_NAME'] ?? '');
    $user = (string) ($_ENV['DB_USER'] ?? '');
    $pass = (string) ($_ENV['DB_PASS'] ?? '');

    return new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name),
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

function purgeFixtures(PDO $pdo): void
{
    purgeIntegClientAuthorizations($pdo);

    $emails = [
        IntegFixtures::PLAYER_EMAIL,
        IntegFixtures::ADMIN_EMAIL,
        IntegFixtures::APPLE_EMAIL,
    ];
    $userUuids = userUuidsForEmails($pdo, $emails);
    if ($userUuids !== []) {
        purgeAuthorizationsForUserIdentifiers($pdo, $userUuids);
    }

    $ids = userIdsForEmails($pdo, $emails);
    if ($ids !== []) {
        $in = implode(',', array_map('intval', $ids));
        $pdo->exec("DELETE FROM user_policy_claims WHERE user_id IN ($in)");
        $pdo->exec("DELETE FROM user_logins WHERE user_id IN ($in)");
        $pdo->exec("DELETE FROM users WHERE id IN ($in)");
    }

    $clientIds = [
        IntegFixtures::CONFIDENTIAL_CLIENT_ID,
        IntegFixtures::UI_CREATED_CLIENT_ID,
    ];
    foreach ($clientIds as $clientIdentifier) {
        purgeClientByIdentifier($pdo, $clientIdentifier);
    }
}

function purgeClientByIdentifier(PDO $pdo, string $clientIdentifier): void
{
    $stmt = $pdo->prepare('SELECT id FROM clients WHERE client_id = ?');
    $stmt->execute([$clientIdentifier]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if ($ids === []) {
        return;
    }
    $in = implode(',', $ids);
    $pdo->exec("DELETE FROM client_access WHERE client_id IN ($in)");
    $pdo->exec("DELETE FROM clients WHERE id IN ($in)");
}

function purgeIntegClientAuthorizations(PDO $pdo): void
{
    $stmt = $pdo->prepare('SELECT id FROM clients WHERE client_id = ?');
    $stmt->execute([IntegFixtures::CONFIDENTIAL_CLIENT_ID]);
    $clientIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if ($clientIds === []) {
        return;
    }
    $in = implode(',', $clientIds);
    $pdo->exec("DELETE FROM user_client_authorizations WHERE client_id IN ($in)");
}

/** @param list<string> $userIdentifiers */
function purgeAuthorizationsForUserIdentifiers(PDO $pdo, array $userIdentifiers): void
{
    if ($userIdentifiers === []) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($userIdentifiers), '?'));
    $stmt = $pdo->prepare("DELETE FROM user_client_authorizations WHERE user_identifier IN ($placeholders)");
    $stmt->execute($userIdentifiers);
}

/** @param list<string> $emails */
function userUuidsForEmails(PDO $pdo, array $emails): array
{
    if ($emails === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($emails), '?'));
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email IN ($placeholders)");
    $stmt->execute($emails);

    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** @param list<string> $emails */
function userIdsForEmails(PDO $pdo, array $emails): array
{
    if ($emails === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($emails), '?'));
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email IN ($placeholders)");
    $stmt->execute($emails);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function seedAdminClaim(PDO $pdo, int $userDbId, int $updatedByUserDbId): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO user_policy_claims (user_id, client_id, updated_by_user_id, updated_at, service, provisos, resource)
         VALUES (?, NULL, ?, NOW(), ?, ?, ?)',
    );
    $stmt->execute([
        $userDbId,
        $updatedByUserDbId,
        ServiceCatalog::Idp->value,
        ':0::::',
        'IDP/EditClient',
    ]);
}
