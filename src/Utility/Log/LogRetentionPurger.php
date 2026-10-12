<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Log;

use Amtgard\IdP\Utility\Log\Sqlite\LogPathResolver;
use DateTimeImmutable;
use DateTimeZone;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/** Deletes hourly SQLite files older than stream retention cutoffs. */
final class LogRetentionPurger
{
    public function __construct(
        private readonly LogPathResolver $paths,
        private readonly IdpLogging $logging,
        private readonly int $traceRetentionDays,
        private readonly int $errorRetentionDays,
    ) {
    }

    /**
     * @return list<array{stream: string, files_deleted: int, cutoff: string}>
     */
    public function purgeAll(): array
    {
        return [
            $this->purgeStream(LogStream::Trace, $this->traceRetentionDays),
            $this->purgeStream(LogStream::Error, $this->errorRetentionDays),
        ];
    }

    /**
     * @return array{stream: string, files_deleted: int, cutoff: string}
     */
    public function purgeStream(LogStream $stream, int $retentionDays): array
    {
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . $retentionDays . ' days')
            ->format('Y-m-d\TH:i:s\Z');

        $root = $this->paths->root() . '/' . $stream->value;
        $deleted = 0;
        if (is_dir($root)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.logs.sqlite')) {
                    continue;
                }

                if ($this->isOlderThanRetention($file->getPathname(), $retentionDays)) {
                    if (@unlink($file->getPathname())) {
                        ++$deleted;
                    }
                }
            }
        }

        $this->logging->app()->notice('logs retention purge completed', [
            'stream' => $stream->value,
            'files_deleted' => $deleted,
            'cutoff' => $cutoff,
        ]);

        return [
            'stream' => $stream->value,
            'files_deleted' => $deleted,
            'cutoff' => $cutoff,
        ];
    }

    private function isOlderThanRetention(string $path, int $retentionDays): bool
    {
        if (! preg_match('#/(\d{4}-\d{2}-\d{2})/(\d{2})\.logs\.sqlite$#', $path, $matches)) {
            return false;
        }

        $fileInstant = DateTimeImmutable::createFromFormat(
            'Y-m-d H',
            $matches[1] . ' ' . $matches[2],
            new DateTimeZone('UTC'),
        );
        if ($fileInstant === false) {
            return false;
        }

        $cutoffInstant = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . $retentionDays . ' days');

        return $fileInstant < $cutoffInstant;
    }
}
