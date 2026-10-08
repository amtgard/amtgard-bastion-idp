<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Log;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final class CorrelationProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $requestId = RequestLogContext::id();
        if ($requestId === null) {
            return $record;
        }

        $extra = $record->extra;
        $extra['request_id'] = $requestId;

        return $record->with(extra: $extra);
    }
}
