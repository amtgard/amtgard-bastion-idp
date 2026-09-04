<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IdP\Utility\CallConsumersBackoff;
use PHPUnit\Framework\TestCase;

class CallConsumersBackoffTest extends TestCase
{
    public function testMissSequenceDoublesUntilCap(): void
    {
        $backoff = new CallConsumersBackoff();

        $this->assertSame(0, $backoff->currentSleepMs());
        $this->assertSame(1, $backoff->next(false));
        $this->assertSame(2, $backoff->next(false));
        $this->assertSame(4, $backoff->next(false));
        $this->assertSame(8, $backoff->next(false));
        $this->assertSame(16, $backoff->next(false));
        $this->assertSame(32, $backoff->next(false));
        $this->assertSame(64, $backoff->next(false));
        $this->assertSame(100, $backoff->next(false));
        $this->assertSame(100, $backoff->next(false));
        $this->assertSame(100, $backoff->currentSleepMs());
    }

    public function testHitResetsSleepToZeroThenMissStartsAtOne(): void
    {
        $backoff = new CallConsumersBackoff();
        $backoff->next(false);
        $backoff->next(false);
        $this->assertSame(2, $backoff->currentSleepMs());

        $this->assertSame(0, $backoff->next(true));
        $this->assertSame(0, $backoff->currentSleepMs());
        $this->assertSame(1, $backoff->next(false));
    }
}
