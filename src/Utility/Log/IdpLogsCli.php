<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Log;

use Amtgard\IdP\Utility\Log\Sqlite\LogPathResolver;
use Amtgard\IdP\Utility\Log\Sqlite\LogSpoolDrainer;
use Amtgard\IdP\Utility\Log\Sqlite\MonologJsonLineParser;
use Amtgard\IdP\Utility\Log\Sqlite\SqliteLogQuery;
use Amtgard\IdP\Utility\Log\Sqlite\SqliteLogSchema;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/** Shared idp-logs CLI behavior (query/bundle/drain) for scripts and tests. */
final class IdpLogsCli
{
    public function __construct(
        private readonly LogPathResolver $paths,
        private readonly SqliteLogQuery $query,
        private readonly LogSpoolDrainer $drainer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function fromProjectRoot(string $projectRoot, ?LoggerInterface $logger = null): self
    {
        $paths = LogPathResolver::fromEnv($projectRoot);
        $schema = new SqliteLogSchema();
        $log = $logger ?? new Logger('idp-logs-cli');

        return new self(
            $paths,
            new SqliteLogQuery($paths, $schema),
            new LogSpoolDrainer($paths, $schema, new MonologJsonLineParser()),
            $log,
        );
    }

    public static function withTestLogger(string $projectRoot): array
    {
        $handler = new TestHandler();
        $logger = new Logger('idp-logs-cli');
        $logger->pushHandler($handler);
        $cli = self::fromProjectRoot($projectRoot, $logger);

        return [$cli, $handler];
    }

    /**
     * @return list<string>
     */
    public function queryRequestId(string $requestId): array
    {
        $lines = $this->query->rawLinesForRequestId($requestId);
        $this->logger->info('idp logs cli query', [
            'command' => 'query',
            'row_count' => count($lines),
        ]);

        return $lines;
    }

    /**
     * @return array{path: string, row_count: int}
     */
    public function bundleRequestId(string $requestId, ?string $outPath = null): array
    {
        $lines = $this->query->rawLinesForRequestId($requestId);
        $this->logger->info('idp logs cli query', [
            'command' => 'bundle',
            'row_count' => count($lines),
        ]);

        if ($outPath === null || $outPath === '') {
            $outPath = $this->paths->root() . '/exports/request-' . $requestId . '.jsonl';
        }
        $this->paths->ensureDirectory($outPath);
        $payload = implode("\n", $lines) . ($lines === [] ? '' : "\n");
        if (file_put_contents($outPath, $payload) === false) {
            throw new \RuntimeException('Unable to write bundle: ' . $outPath);
        }

        return ['path' => $outPath, 'row_count' => count($lines)];
    }

    public function drainOnce(): int
    {
        return $this->drainer->drainOnce();
    }
}
