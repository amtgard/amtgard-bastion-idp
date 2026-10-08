<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility\Log;

use Amtgard\IdP\Utility\Log\CorrelationProcessor;
use Amtgard\IdP\Utility\Log\RequestLogContext;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class CorrelationProcessorTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestLogContext::reset();
    }

    public function testAddsRequestAndIdentityFieldsToExtra(): void
    {
        RequestLogContext::set('req-abc12345');
        RequestLogContext::setIdentity('user-1', 'client-1');

        $processor = new CorrelationProcessor();
        $record = new LogRecord(
            new \DateTimeImmutable(),
            'security',
            Level::Debug,
            'test',
            [],
            [],
        );

        $processed = $processor($record);

        $this->assertSame('req-abc12345', $processed->extra['request_id']);
        $this->assertSame('user-1', $processed->extra['user_uuid']);
        $this->assertSame('client-1', $processed->extra['client_id']);
        $this->assertSame('client-1', $processed->extra['aud']);
    }
}
