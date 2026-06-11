<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\ClientMetadata;

use Amtgard\IdP\Utility\ClientMetadataValidator;

final class MetadataSizeGuard
{
    public static function assertWithinLimit(string $payload): void
    {
        if (strlen($payload) > ClientMetadataValidator::MAX_BYTES) {
            throw new \InvalidArgumentException(
                sprintf('metadata must be at most %d bytes.', ClientMetadataValidator::MAX_BYTES)
            );
        }
    }
}
