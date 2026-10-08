<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Log;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final class CorrelationProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;

        $requestId = RequestLogContext::id();
        if ($requestId !== null) {
            $extra['request_id'] = $requestId;
        }

        $userUuid = RequestLogContext::userUuid();
        if ($userUuid !== null) {
            $extra['user_uuid'] = $userUuid;
        }

        $clientId = RequestLogContext::clientId();
        if ($clientId !== null) {
            $extra['client_id'] = $clientId;
        }

        $aud = RequestLogContext::aud();
        if ($aud !== null) {
            $extra['aud'] = $aud;
        }

        if ($extra === $record->extra) {
            return $record;
        }

        return $record->with(extra: $extra);
    }
}
