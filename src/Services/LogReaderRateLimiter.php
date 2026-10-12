<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services;

/** In-process token bucket for log-read API routes (pilot limits). */
final class LogReaderRateLimiter
{
    /** @var array<string, array{count: int, window_start: int}> */
    private array $buckets = [];

    public function __construct(
        private readonly int $queryPerMinute = 300,
        private readonly int $analyzePerMinute = 60,
    ) {
    }

    public function allowQuery(string $subject): bool
    {
        return $this->allow($subject, 'query', $this->queryPerMinute);
    }

    public function allowAnalyze(string $subject): bool
    {
        return $this->allow($subject, 'analyze', $this->analyzePerMinute);
    }

    private function allow(string $subject, string $routeClass, int $limit): bool
    {
        $key = $subject . ':' . $routeClass;
        $now = time();
        $bucket = $this->buckets[$key] ?? ['count' => 0, 'window_start' => $now];
        if ($now - $bucket['window_start'] >= 60) {
            $bucket = ['count' => 0, 'window_start' => $now];
        }

        if ($bucket['count'] >= $limit) {
            return false;
        }

        ++$bucket['count'];
        $this->buckets[$key] = $bucket;

        return true;
    }
}
