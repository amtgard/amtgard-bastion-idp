<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Log\Sqlite;

use DateTimeImmutable;
use DateTimeZone;

/** Hourly SQLite paths and spool directory under LOG_ROOT. */
final class LogPathResolver
{
    public function __construct(
        private readonly string $root,
    ) {
    }

    public static function fromEnv(string $projectRoot): self
    {
        $configured = trim((string) ($_ENV['LOG_ROOT'] ?? ''));
        if ($configured === '') {
            $configured = $projectRoot . '/logs/bundles';
        }

        return new self(rtrim($configured, '/'));
    }

    public function root(): string
    {
        return $this->root;
    }

    public function spoolDirectory(): string
    {
        return $this->root . '/spool';
    }

    public function spoolFile(): string
    {
        return $this->spoolDirectory() . '/active.jsonl';
    }

    public function sqlitePathForInstant(DateTimeImmutable $instant): string
    {
        $utc = $instant->setTimezone(new DateTimeZone('UTC'));
        $day = $utc->format('Y-m-d');
        $hour = $utc->format('H');

        return $this->root . '/trace/' . $day . '/' . $hour . '.logs.sqlite';
    }

    public function ensureDirectory(string $path): void
    {
        $dir = dirname($path);
        if (is_dir($dir)) {
            return;
        }
        if (! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Unable to create log directory: ' . $dir);
        }
    }
}
