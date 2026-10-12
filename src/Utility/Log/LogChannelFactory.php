<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Log;

use Amtgard\IdP\Utility\Log\Sqlite\JsonLogSpoolHandler;
use Amtgard\IdP\Utility\Log\Sqlite\LogPathResolver;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\ProcessorInterface;
use Psr\Log\LoggerInterface;

/** Builds Monolog loggers per channel with shared processors and handlers. */
final class LogChannelFactory
{
    /** @var array<string, LoggerInterface> */
    private array $cache = [];

    public function __construct(
        private readonly LogPathResolver $paths,
        private readonly Level $traceLevel,
        private readonly bool $spoolEnabled,
        private readonly bool $stderrEnabled,
        private readonly string $appLogFile,
    ) {
    }

    public function forChannel(LogChannel $channel): LoggerInterface
    {
        $name = $channel->value;
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        $logger = new Logger($name);
        foreach ($this->processors() as $processor) {
            $logger->pushProcessor($processor);
        }

        $handlers = [];
        if ($this->stderrEnabled) {
            $stderr = new StreamHandler('php://stderr', $this->traceLevel);
            $stderr->setFormatter(new JsonFormatter());
            $handlers[] = $stderr;
        }

        if ($this->spoolEnabled) {
            $handlers[] = new JsonLogSpoolHandler($this->paths);
        }

        $fileHandler = new StreamHandler($this->appLogFile, $this->traceLevel);
        $handlers[] = $fileHandler;

        $handlers[] = new ErrorLogHandler(level: Level::Error);

        $logger->pushHandler(new WhatFailureGroupHandler($handlers));
        $this->cache[$name] = $logger;

        return $logger;
    }

    /**
     * @return list<ProcessorInterface>
     */
    private function processors(): array
    {
        return [
            new CorrelationProcessor(),
            new RedactionProcessor(),
        ];
    }
}
