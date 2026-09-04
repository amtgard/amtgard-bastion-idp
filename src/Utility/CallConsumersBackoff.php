<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

/**
 * D17 poll backoff around empty {@see \Amtgard\SetQueue\PubSubQueue::callConsumers}.
 *
 * Units are milliseconds. Hit (a job was dequeued) resets sleep to 0.
 * Miss: if last sleep was 0 then 1ms, else min(100, last * 2).
 * Callers apply the delay with usleep(ms * 1000). This class does not sleep.
 */
final class CallConsumersBackoff
{
    public const MAX_SLEEP_MS = 100;

    private int $sleepMs = 0;

    public function next(bool $hit): int
    {
        if ($hit) {
            $this->sleepMs = 0;

            return 0;
        }

        if ($this->sleepMs === 0) {
            $this->sleepMs = 1;
        } else {
            $this->sleepMs = min(self::MAX_SLEEP_MS, $this->sleepMs * 2);
        }

        return $this->sleepMs;
    }

    public function currentSleepMs(): int
    {
        return $this->sleepMs;
    }
}
