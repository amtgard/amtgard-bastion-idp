<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

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
        $encoding = self::normalizeEncoding($encoding);

        if ($encoding === self::ENCODING_BASE64) {
            return self::prepareBase64($metadata);
        }

        return self::prepareJsonObject($metadata);
    }

    /**
     * @return array<string, mixed>
     */
    public static function validate(mixed $metadata): array
    {
        return self::prepareJsonObject($metadata)['decoded'];
    }

    /**
     * @return array{payload: string, encoding: string, decoded: array<string, mixed>}
     */
    private static function prepareJsonObject(mixed $metadata): array
    {
        if (!is_array($metadata) || array_is_list($metadata)) {
            throw new \InvalidArgumentException('metadata must be a JSON object.');
        }

        $encoded = json_encode($metadata, JSON_THROW_ON_ERROR);
        self::assertWithinLimit($encoded);

        return [
            'payload' => $encoded,
            'encoding' => self::ENCODING_JSON,
            'decoded' => $metadata,
        ];
    }

    /**
     * @return array{payload: string, encoding: string}
     */
    private static function prepareBase64(mixed $metadata): array
    {
        if (!is_string($metadata) || trim($metadata) === '') {
            throw new \InvalidArgumentException('metadata must be a non-empty base64 string when encoding is base64.');
        }

        $payload = trim($metadata);
        self::assertWithinLimit($payload);

        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('metadata must be valid base64 when encoding is base64.');
        }

        $json = json_decode($decoded, true);
        if (!is_array($json) || array_is_list($json)) {
            throw new \InvalidArgumentException('base64 metadata must decode to a JSON object.');
        }

        if (strlen($decoded) > self::MAX_BYTES) {
            throw new \InvalidArgumentException(
                sprintf('decoded metadata must be at most %d bytes.', self::MAX_BYTES)
            );
        }

        return [
            'payload' => $payload,
            'encoding' => self::ENCODING_BASE64,
        ];
    }

    private static function normalizeEncoding(?string $encoding): string
    {
        $encoding = $encoding === null || $encoding === '' ? self::ENCODING_JSON : strtolower(trim($encoding));
        if (!in_array($encoding, [self::ENCODING_JSON, self::ENCODING_BASE64], true)) {
            throw new \InvalidArgumentException('encoding must be json or base64.');
        }

        return $encoding;
    }

    private static function assertWithinLimit(string $payload): void
    {
        if (strlen($payload) > self::MAX_BYTES) {
            throw new \InvalidArgumentException(
                sprintf('metadata must be at most %d bytes.', self::MAX_BYTES)
            );
        }
    }
}
