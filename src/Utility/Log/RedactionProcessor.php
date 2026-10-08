<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Log;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/** Redact sensitive keys in log context (design denylist). */
final class RedactionProcessor implements ProcessorInterface
{
    private const SENSITIVE_PATTERN = '/(?:secret|token|password|authorization|cookie|client_secret|access_token|refresh_token|csrf|management_key|private_key|pem)/i';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->redactArray($record->context),
            extra: $this->redactArray($record->extra),
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function redactArray(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if (preg_match(self::SENSITIVE_PATTERN, $key)) {
                $out[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $out[$key] = $this->redactArray($value);
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
