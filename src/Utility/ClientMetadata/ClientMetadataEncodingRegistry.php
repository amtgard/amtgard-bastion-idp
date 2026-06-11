<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\ClientMetadata;

use Amtgard\IdP\Utility\ClientMetadataValidator;

/**
 * Selects a metadata wire encoding strategy (json object vs base64 blob).
 */
final class ClientMetadataEncodingRegistry
{
    /** @var array<string, ClientMetadataEncodingStrategy> */
    private static array $strategies = [
        ClientMetadataValidator::ENCODING_JSON => JsonObjectMetadataEncodingStrategy::class,
        ClientMetadataValidator::ENCODING_BASE64 => Base64MetadataEncodingStrategy::class,
    ];

    public static function resolve(?string $encoding): ClientMetadataEncodingStrategy
    {
        $normalized = self::normalizeEncoding($encoding);
        $strategyClass = self::$strategies[$normalized];

        return new $strategyClass();
    }

    private static function normalizeEncoding(?string $encoding): string
    {
        $encoding = $encoding === null || $encoding === ''
            ? ClientMetadataValidator::ENCODING_JSON
            : strtolower(trim($encoding));

        if (!isset(self::$strategies[$encoding])) {
            throw new \InvalidArgumentException('encoding must be json or base64.');
        }

        return $encoding;
    }
}
