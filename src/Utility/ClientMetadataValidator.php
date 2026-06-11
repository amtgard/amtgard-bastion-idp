<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

use Amtgard\IdP\Utility\ClientMetadata\ClientMetadataEncodingRegistry;
use Amtgard\IdP\Utility\ClientMetadata\JsonObjectMetadataEncodingStrategy;

final class ClientMetadataValidator
{
    public const MAX_BYTES = 300;
    public const ENCODING_JSON = 'json';
    public const ENCODING_BASE64 = 'base64';

    /**
     * @return array{payload: string, encoding: string}
     */
    public static function prepare(mixed $metadata, ?string $encoding = null): array
    {
        return ClientMetadataEncodingRegistry::resolve($encoding)->prepare($metadata);
    }

    /**
     * @return array<string, mixed>
     */
    public static function validate(mixed $metadata): array
    {
        $prepared = (new JsonObjectMetadataEncodingStrategy())->prepare($metadata);

        return json_decode($prepared['payload'], true, flags: JSON_THROW_ON_ERROR);
    }
}
