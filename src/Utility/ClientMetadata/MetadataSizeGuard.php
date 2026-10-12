<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\ClientMetadata;

use Amtgard\IdP\Utility\ClientMetadataValidator;
use Amtgard\IdP\Utility\Log\IdpLoggingRuntime;

final class MetadataSizeGuard
{
    public static function assertWithinLimit(string $payload): void
    {
        $len = strlen($payload);
        if ($len > ClientMetadataValidator::MAX_BYTES) {
            IdpLoggingRuntime::app()->debug('client metadata size rejected', [
                'payload_len' => $len,
                'max_bytes' => ClientMetadataValidator::MAX_BYTES,
            ]);
            throw new \InvalidArgumentException(
                sprintf('metadata must be at most %d bytes.', ClientMetadataValidator::MAX_BYTES)
            );
        }
    }
}
