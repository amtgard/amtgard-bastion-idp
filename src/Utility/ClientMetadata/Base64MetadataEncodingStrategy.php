<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\ClientMetadata;

use Amtgard\IdP\Utility\ClientMetadataValidator;

final class Base64MetadataEncodingStrategy implements ClientMetadataEncodingStrategy
{
    public function encodingName(): string
    {
        return ClientMetadataValidator::ENCODING_BASE64;
    }

    public function prepare(mixed $metadata): array
    {
        if (!is_string($metadata) || trim($metadata) === '') {
            throw new \InvalidArgumentException('metadata must be a non-empty base64 string when encoding is base64.');
        }

        $payload = trim($metadata);
        MetadataSizeGuard::assertWithinLimit($payload);

        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('metadata must be valid base64 when encoding is base64.');
        }

        $json = json_decode($decoded, true);
        if (!is_array($json) || array_is_list($json)) {
            throw new \InvalidArgumentException('base64 metadata must decode to a JSON object.');
        }

        MetadataSizeGuard::assertWithinLimit($decoded);

        return [
            'payload' => $payload,
            'encoding' => self::encodingName(),
        ];
    }
}
