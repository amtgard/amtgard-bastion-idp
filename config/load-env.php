<?php

declare(strict_types=1);

/**
 * Application `.env` (non-secrets) then vault inject `secrets.env` on `/run/secrets`.
 * Second load uses mutable Dotenv so inject overrides overlapping keys from `.env`.
 *
 * In DEV, repo `.env` is loaded again (mutable) so local MariaDB, Google/Facebook/Discord
 * secrets, and other laptop overrides beat stale vault inject bundles.
 */
function idp_load_process_env(string $projectRoot): void
{
    Dotenv\Dotenv::createImmutable($projectRoot)->safeLoad();

    if (idp_is_dev_integ_environment()) {
        // Laptop `.env` often points ORK JSON at a local ORK3 container; integ uses DevIntegHttpClient stubs on ork.amtgard.com.
        idp_clear_process_env_key('ORK_JSON_API_BASE_URL');
        idp_clear_process_env_key('ORK_CLAIM_MAIL_VIA_ORK');

        return;
    }

    $injectDir = getenv('IDP_INJECT_SECRETS_DIR');
    if (!is_string($injectDir) || $injectDir === '') {
        $injectDir = '/run/secrets';
    }
    $injectFile = $injectDir . '/secrets.env';
    if (is_readable($injectFile)) {
        Dotenv\Dotenv::createMutable($injectDir, 'secrets.env')->load();
    }

    idp_apply_inject_file_paths_when_unset($injectDir);

    if (idp_is_dev_environment($projectRoot)) {
        Dotenv\Dotenv::createMutable($projectRoot)->load();
    }
}

/**
 * Catalog inject_files may exist on disk while an older render omitted path keys from secrets.env.
 */
function idp_apply_inject_file_paths_when_unset(string $injectDir): void
{
    $pairs = [
        'OAUTH_PRIVATE_KEY' => 'oauth-private.key',
        'OAUTH_PUBLIC_KEY' => 'oauth-public.key',
        'APPLE_KEY_FILE_PATH' => 'apple.p8',
    ];

    foreach ($pairs as $envKey => $basename) {
        $current = $_ENV[$envKey] ?? getenv($envKey);
        if (is_string($current) && $current !== '') {
            continue;
        }

        $path = $injectDir . '/files/' . $basename;
        if (!is_readable($path)) {
            continue;
        }

        $_ENV[$envKey] = $path;
        putenv($envKey . '=' . $path);
    }
}

function idp_runtime_environment(): ?string
{
    $environment = $_ENV['ENVIRONMENT'] ?? getenv('ENVIRONMENT');
    if (!is_string($environment) || $environment === '') {
        $environment = $_SERVER['ENVIRONMENT'] ?? null;
    }

    return is_string($environment) && $environment !== '' ? $environment : null;
}

function idp_is_dev_integ_environment(): bool
{
    $environment = idp_runtime_environment();

    return is_string($environment) && strtoupper($environment) === 'DEV_INTEG';
}

function idp_is_dev_environment(string $projectRoot): bool
{
    if (idp_is_dev_integ_environment()) {
        return false;
    }

    $fromRepoDotenv = idp_repo_dotenv_environment($projectRoot);
    if ($fromRepoDotenv !== null) {
        return strtoupper($fromRepoDotenv) === 'DEV';
    }

    $environment = idp_runtime_environment();

    return is_string($environment) && strtoupper($environment) === 'DEV';
}

function idp_clear_process_env_key(string $key): void
{
    unset($_ENV[$key], $_SERVER[$key]);
    putenv($key);
}

function idp_repo_dotenv_environment(string $projectRoot): ?string
{
    $path = $projectRoot . '/.env';
    if (!is_readable($path)) {
        return null;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return null;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_starts_with($line, 'ENVIRONMENT=')) {
            continue;
        }

        $value = trim(substr($line, strlen('ENVIRONMENT=')));
        $value = trim($value, "\"'");

        return $value !== '' ? $value : null;
    }

    return null;
}
