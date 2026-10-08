<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Log\Sqlite;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

/** Append formatted Monolog JSON lines to the spool file (non-blocking). */
final class JsonLogSpoolHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly LogPathResolver $paths,
    ) {
        parent::__construct();
        $this->setFormatter(new JsonFormatter());
    }

    protected function write(LogRecord $record): void
    {
        $spool = $this->paths->spoolFile();
        $this->paths->ensureDirectory($spool);
        $this->paths->ensureDirectory($this->paths->spoolDirectory());

        $line = trim($this->getFormatter()->format($record)) . "\n";
        @file_put_contents($spool, $line, FILE_APPEND | LOCK_EX);
    }
}
