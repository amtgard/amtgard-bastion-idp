<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Setup\HostEnv;

use Amtgard\Traits\Builder\Builder;
use Psr\Log\LoggerInterface;

/**
 * Applies host-env-vault inject render trees at process start (Facade).
 */
final class HostEnvBootstrap
{
    use Builder;

    private ?InjectedSecretsLoader $injectLoader = null;

    private ?LoggerInterface $logger = null;

    public function injectLoader(InjectedSecretsLoader $injectLoader): self
    {
        $this->injectLoader = $injectLoader;

        return $this;
    }

    public function logger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function applyFromProcessEnv(): void
    {
        $secretsDir = $this->readNonEmpty('HOST_ENV_SECRETS_DIR');
        if ($secretsDir === '') {
            return;
        }

        $loader = ($this->injectLoader ?? InjectedSecretsLoader::builder()->build())
            ->secretsDir($secretsDir);
        if ($this->logger !== null) {
            $loader->logger($this->logger);
        }
        $loader->applyMissing();
    }

    private function readNonEmpty(string $key): string
    {
        $fromGetenv = getenv($key);
        if (is_string($fromGetenv) && $fromGetenv !== '') {
            return $fromGetenv;
        }

        $fromSuperglobal = $_ENV[$key] ?? null;
        if (is_string($fromSuperglobal) && $fromSuperglobal !== '') {
            return $fromSuperglobal;
        }

        return '';
    }
}
