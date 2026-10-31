<?php

declare(strict_types=1);

function idp_runtime_environment(): ?string
{
    $environment = $_ENV['ENVIRONMENT'] ?? getenv('ENVIRONMENT');
    if (!is_string($environment) || $environment === '') {
        $environment = $_SERVER['ENVIRONMENT'] ?? null;
    }

    return is_string($environment) && $environment !== '' ? $environment : null;
}

function idp_is_prod_environment(): bool
{
    $environment = idp_runtime_environment();

    return is_string($environment) && strtoupper($environment) === 'PROD';
}
