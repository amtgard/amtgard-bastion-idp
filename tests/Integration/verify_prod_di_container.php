<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$environment = getenv('ENVIRONMENT') ?: ($_ENV['ENVIRONMENT'] ?? '');
if (strtoupper((string) $environment) !== 'PROD') {
    fwrite(STDERR, "Expected ENVIRONMENT=PROD, got {$environment}\n");
    exit(1);
}

$projectRoot = dirname(__DIR__, 2);
$compileDir = $projectRoot . '/cache/di-integ-probe';
putenv('IDP_DI_COMPILE_DIR=' . $compileDir);
$_ENV['IDP_DI_COMPILE_DIR'] = $compileDir;
if (is_dir($compileDir)) {
    foreach (glob($compileDir . '/*') ?: [] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}

$container = require $projectRoot . '/config/bootstrap.php';
$class = $container::class;
if ($class !== 'CompiledContainer' && !str_ends_with($class, 'CompiledContainer')) {
    fwrite(STDERR, "Expected compiled PHP-DI container, got {$class}\n");
    exit(1);
}

$compiledFile = $compileDir . '/CompiledContainer.php';
if (!is_readable($compiledFile)) {
    fwrite(STDERR, "Missing compiled container file: {$compiledFile}\n");
    exit(1);
}

$container->get(LoggerInterface::class);

fwrite(STDOUT, "prod_di_container:ok\n");
exit(0);
