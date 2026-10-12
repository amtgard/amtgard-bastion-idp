<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility\Log;

use Amtgard\IdP\Utility\Log\RedactionProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class RedactionProcessorTest extends TestCase
{
    public function testRedactsSensitiveContextKeys(): void
    {
        $processor = new RedactionProcessor();
        $record = new LogRecord(
            new \DateTimeImmutable(),
            'app',
            Level::Info,
            'test',
            ['client_secret' => 'sekrit', 'client_id' => 'public'],
            ['access_token' => 'tok'],
        );

        $processed = $processor($record);

        $this->assertSame('[redacted]', $processed->context['client_secret']);
        $this->assertSame('public', $processed->context['client_id']);
        $this->assertSame('[redacted]', $processed->extra['access_token']);
    }
}
