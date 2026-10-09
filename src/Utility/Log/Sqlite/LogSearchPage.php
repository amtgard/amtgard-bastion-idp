<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Log\Sqlite;

/** One page of structured log rows plus optional continuation cursor. */
final class LogSearchPage
{
    /**
     * @param list<array<string, mixed>> $entries
     */
    public function __construct(
        public readonly array $entries,
        public readonly ?string $nextCursor,
    ) {
    }
}
