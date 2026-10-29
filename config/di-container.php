<?php

declare(strict_types=1);

use DI\Container;
use DI\ContainerBuilder;

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

function idp_di_compilation_directory(string $projectRoot): string
{
    return $projectRoot . '/cache/di';
}

function idp_configure_di_container_builder(ContainerBuilder $builder, string $projectRoot): void
{
    if (!idp_is_prod_environment()) {
        return;
    }

    $directory = idp_di_compilation_directory($projectRoot);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create PHP-DI compilation directory: ' . $directory);
    }

    $builder->enableCompilation($directory);
}

function idp_build_di_container(string $projectRoot): Container
{
    $builder = new ContainerBuilder();
    $builder->addDefinitions($projectRoot . '/config/container.php');
    $builder->useAutowiring(true);
    idp_configure_di_container_builder($builder, $projectRoot);

    return $builder->build();
}
