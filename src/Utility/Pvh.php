<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

final class Pvh
{
    public const PVH_HEX_LENGTH = 44;
    public const POLICY_HASH_BYTE_LENGTH = 32;
    public const HASH_PREFIX_BYTE_LENGTH = 16;
    public const TIMESTAMP_BYTE_LENGTH = 6;

    private const MAX_NOW_MS = (1 << 48) - 1;

    /** Canonical SHA-256 of aud || "\n" || policyJson || "\n" || canonicalMetadata. Returns 32 raw bytes. */
    public static function policyHash(string $aud, string $policyJson, string $canonicalMetadata = ''): string
    {
        return hash('sha256', $aud . "\n" . $policyJson . "\n" . $canonicalMetadata, true);
    }

    /**
     * Exact JWT client_metadata encoding used at mint: strings (base64 blobs) as-is,
     * arrays/objects as JSON, absent/null as empty string. Do not include pvh.
     */
    public static function canonicalMetadata(mixed $metadata): string
    {
        if ($metadata === null) {
            return '';
        }
        if (is_string($metadata)) {
            return $metadata;
        }
        if (!is_array($metadata) && !is_object($metadata)) {
            return '';
        }

        return json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function isPvhHex(string $pvh): bool
    {
        return strlen($pvh) === self::PVH_HEX_LENGTH && hex2bin($pvh) !== false;
    }

    public static function policyHashToHex(string $policyHash): string
    {
        self::assertPolicyHash($policyHash);

        return bin2hex($policyHash);
    }

    public static function policyHashFromHex(string $hex): string
    {
        $bytes = hex2bin($hex);
        if ($bytes === false) {
            throw new \InvalidArgumentException('policy hash hex is invalid.');
        }

        self::assertPolicyHash($bytes);

        return $bytes;
    }

    /**
     * 44-char lowercase hex = 12-char 6-byte big-endian unix milliseconds + 32-char first 16 bytes of policy_hash.
     * $policyHash is 32 raw bytes. $nowMs is unix milliseconds (int).
     */
    public static function encode(int $nowMs, string $policyHash): string
    {
        self::assertNowMs($nowMs);
        self::assertPolicyHash($policyHash);

        return sprintf('%012x', $nowMs) . bin2hex(substr($policyHash, 0, self::HASH_PREFIX_BYTE_LENGTH));
    }

    /**
     * Sticky timestamp (D7): if currentHash is non-null and hash_equals(newHash, currentHash) and currentPvh is non-null,
     * return currentPvh unchanged. Otherwise mint encode(nowMs, newHash).
     * Hashes are 32 raw bytes.
     */
    public static function reuseOrMint(string $newHash, ?string $currentPvh, ?string $currentHash, int $nowMs): string
    {
        if ($currentHash !== null && $currentPvh !== null && hash_equals($newHash, $currentHash)) {
            return $currentPvh;
        }

        return self::encode($nowMs, $newHash);
    }

    /** First 12 hex chars of a 44-char pvh → unix ms as int. */
    public static function timestampMs(string $pvh): int
    {
        self::assertPvhHex($pvh);

        return hexdec(substr($pvh, 0, self::TIMESTAMP_BYTE_LENGTH * 2));
    }

    /** Last 32 hex chars (first 16 bytes of policy_hash as hex). */
    public static function hashPrefixHex(string $pvh): string
    {
        self::assertPvhHex($pvh);

        return substr($pvh, self::TIMESTAMP_BYTE_LENGTH * 2);
    }

    private static function assertPolicyHash(string $policyHash): void
    {
        if (strlen($policyHash) !== self::POLICY_HASH_BYTE_LENGTH) {
            throw new \InvalidArgumentException('policyHash must be 32 raw bytes.');
        }
    }

    private static function assertNowMs(int $nowMs): void
    {
        if ($nowMs < 0 || $nowMs > self::MAX_NOW_MS) {
            throw new \InvalidArgumentException('nowMs must be in 0..2^48-1.');
        }
    }

    private static function assertPvhHex(string $pvh): void
    {
        if (strlen($pvh) !== self::PVH_HEX_LENGTH || hex2bin($pvh) === false) {
            throw new \InvalidArgumentException('pvh must be 44-char hex.');
        }
    }
}
