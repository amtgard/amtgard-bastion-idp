<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Log\Sqlite;

use Amtgard\IdP\Utility\Log\LogStream;

/** Filters for scanning hourly SQLite log files. */
final class LogSearchCriteria
{
    public function __construct(
        public readonly LogStream $stream,
        public readonly int $limit,
        public readonly ?string $requestId = null,
        public readonly ?string $userUuid = null,
        public readonly ?string $clientId = null,
        public readonly ?string $aud = null,
        public readonly ?string $channel = null,
        public readonly ?int $minLevel = null,
        public readonly ?string $since = null,
        public readonly ?string $until = null,
        public readonly ?string $cursor = null,
    ) {
    }
}
