<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Config;

use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Getter;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Strategy selector for container include files (Registry + Strategy).
 */
final class EnvironmentLoader
{
    use Builder;
    use Getter;

    protected string $liveIncludesDir = '';

    protected string $integIncludesDir = '';

    protected string $environment = 'DEV';

    protected string $integEnvironment = 'DEV_INTEG';

    protected ?LoggerInterface $logger = null;

    /** @var array<string, array{live: string, integ: ?string}> */
    protected array $modules = [];

    public function register(string $module, string $liveFile, ?string $integFile): void
    {
        $this->modules[$module] = [
            'live' => $liveFile,
            'integ' => $integFile,
        ];
    }

    public function isInteg(): bool
    {
        return $this->environment === $this->integEnvironment;
    }

    public function emit(string $module): string
    {
        if (!isset($this->modules[$module])) {
            throw new \InvalidArgumentException(sprintf('Unknown container module: %s', $module));
        }

        $entry = $this->modules[$module];
        $useInteg = $this->isInteg() && $entry['integ'] !== null;
        $file = $useInteg ? $entry['integ'] : $entry['live'];
        $dir = $useInteg ? $this->integIncludesDir : $this->liveIncludesDir;
        $path = rtrim($dir, '/') . '/' . $file;

        if ($this->logger !== null) {
            $this->logger->log(
                LogLevel::DEBUG,
                'EnvironmentLoader selected container include',
                [
                    'module' => $module,
                    'environment' => $this->environment,
                    'include' => $file,
                    'integ' => $useInteg,
                ]
            );
        }

        return $path;
    }
}
