<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\ClientMetadata;

use Amtgard\IdP\Utility\ClientMetadataValidator;

final class JsonObjectMetadataEncodingStrategy implements ClientMetadataEncodingStrategy
{
    public function encodingName(): string
    {
        return ClientMetadataValidator::ENCODING_JSON;
    }

    public function prepare(mixed $metadata): array
    {
        if (!is_array($metadata) || array_is_list($metadata)) {
            throw new \InvalidArgumentException('metadata must be a JSON object.');
        }

        $encoded = json_encode($metadata, JSON_THROW_ON_ERROR);
        MetadataSizeGuard::assertWithinLimit($encoded);

        return [
            'payload' => $encoded,
            'encoding' => self::encodingName(),
        ];
    }
}
