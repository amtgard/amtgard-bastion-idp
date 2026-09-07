<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IdP\Utility\Pvh;
use PHPUnit\Framework\TestCase;

class PvhTest extends TestCase
{
    private const AUD = 'ork-client';
    private const POLICY_A = '["orn:a"]';
    private const POLICY_AB = '["orn:a","orn:b"]';
    private const METADATA = '{"role":"x"}';

    public function testIdenticalInputsProduceIdenticalPolicyHash(): void
    {
        $hash1 = Pvh::policyHash(self::AUD, self::POLICY_A, self::METADATA);
        $hash2 = Pvh::policyHash(self::AUD, self::POLICY_A, self::METADATA);
        $hex1 = Pvh::policyHashToHex($hash1);
        $hex2 = Pvh::policyHashToHex($hash2);

        $this->assertSame(Pvh::POLICY_HASH_BYTE_LENGTH, strlen($hash1));
        $this->assertSame($hash1, $hash2);
        $this->assertSame($hex1, $hex2);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hex1);
        $this->assertSame($hash1, Pvh::policyHashFromHex($hex1));
    }

    public function testAddClaimJsonChangesPolicyHash(): void
    {
        $this->assertNotSame(
            Pvh::policyHash(self::AUD, self::POLICY_A),
            Pvh::policyHash(self::AUD, self::POLICY_AB)
        );
    }

    public function testDeleteClaimJsonChangesPolicyHash(): void
    {
        $this->assertNotSame(
            Pvh::policyHash(self::AUD, self::POLICY_AB),
            Pvh::policyHash(self::AUD, self::POLICY_A)
        );
    }

    public function testMetadataIncludedInPolicyHash(): void
    {
        $empty = Pvh::policyHash(self::AUD, self::POLICY_A, '');
        $withRole = Pvh::policyHash(self::AUD, self::POLICY_A, self::METADATA);
        $withRoleAgain = Pvh::policyHash(self::AUD, self::POLICY_A, self::METADATA);
        $otherRole = Pvh::policyHash(self::AUD, self::POLICY_A, '{"role":"y"}');

        $this->assertNotSame($empty, $withRole);
        $this->assertNotSame($empty, Pvh::policyHash(self::AUD, self::POLICY_A, self::METADATA));
        $this->assertSame($withRole, $withRoleAgain);
        $this->assertNotSame($withRole, $otherRole);
    }

    public function testDifferentAudChangesPolicyHash(): void
    {
        $this->assertNotSame(
            Pvh::policyHash('aud-a', self::POLICY_A, self::METADATA),
            Pvh::policyHash('aud-b', self::POLICY_A, self::METADATA)
        );
    }

    public function testEncodeIsFortyFourCharLowercaseHex(): void
    {
        $hash = Pvh::policyHash(self::AUD, self::POLICY_A);
        $nowMs = 1_700_000_000_000;
        $pvh = Pvh::encode($nowMs, $hash);

        $this->assertSame(Pvh::PVH_HEX_LENGTH, strlen($pvh));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{44}$/', $pvh);
        $this->assertSame($nowMs, Pvh::timestampMs($pvh));
        $this->assertSame(bin2hex(substr($hash, 0, Pvh::HASH_PREFIX_BYTE_LENGTH)), Pvh::hashPrefixHex($pvh));
    }

    public function testReuseOrMintKeepsExistingPvhWhenHashUnchanged(): void
    {
        $hash = Pvh::policyHash(self::AUD, self::POLICY_A);
        $original = Pvh::encode(1_700_000_000_000, $hash);
        $reused = Pvh::reuseOrMint($hash, $original, $hash, 1_700_000_000_999);

        $this->assertSame($original, $reused);
        $this->assertSame(1_700_000_000_000, Pvh::timestampMs($reused));
    }

    public function testReuseOrMintMintsWhenHashChanges(): void
    {
        $oldHash = Pvh::policyHash(self::AUD, self::POLICY_A);
        $newHash = Pvh::policyHash(self::AUD, self::POLICY_AB);
        $original = Pvh::encode(1_700_000_000_000, $oldHash);
        $nowMs = 1_700_000_000_500;
        $minted = Pvh::reuseOrMint($newHash, $original, $oldHash, $nowMs);

        $this->assertNotSame($original, $minted);
        $this->assertSame($nowMs, Pvh::timestampMs($minted));
        $this->assertSame(bin2hex(substr($newHash, 0, Pvh::HASH_PREFIX_BYTE_LENGTH)), Pvh::hashPrefixHex($minted));
        $this->assertNotSame(Pvh::hashPrefixHex($original), Pvh::hashPrefixHex($minted));
    }

    public function testReuseOrMintMintsWhenCurrentPvhOrHashIsNull(): void
    {
        $hash = Pvh::policyHash(self::AUD, self::POLICY_A);
        $existing = Pvh::encode(1_700_000_000_000, $hash);
        $nowMs = 1_800_000_000_000;
        $expected = Pvh::encode($nowMs, $hash);

        $this->assertSame($expected, Pvh::reuseOrMint($hash, null, $hash, $nowMs));
        $this->assertSame($expected, Pvh::reuseOrMint($hash, $existing, null, $nowMs));
        $this->assertSame($expected, Pvh::reuseOrMint($hash, null, null, $nowMs));
        $this->assertNotSame($existing, Pvh::reuseOrMint($hash, $existing, null, $nowMs));
    }

    public function testCanonicalMetadataEncodesArraysAndPassesStringsThrough(): void
    {
        $this->assertSame('', Pvh::canonicalMetadata(null));
        $this->assertSame('abc123', Pvh::canonicalMetadata('abc123'));
        $this->assertSame('{"role":"x"}', Pvh::canonicalMetadata(['role' => 'x']));
        $this->assertSame(
            Pvh::policyHash(self::AUD, self::POLICY_A, '{"role":"x"}'),
            Pvh::policyHash(self::AUD, self::POLICY_A, Pvh::canonicalMetadata(['role' => 'x']))
        );
    }

    public function testIsPvhHex(): void
    {
        $pvh = Pvh::encode(1_700_000_000_000, Pvh::policyHash(self::AUD, self::POLICY_A));
        $this->assertTrue(Pvh::isPvhHex($pvh));
        $this->assertFalse(Pvh::isPvhHex('short'));
        $this->assertFalse(Pvh::isPvhHex(str_repeat('z', 44)));
    }

    public function testDifferentHashesAtSameMillisecondProduceDifferentPvh(): void
    {
        $hashA = Pvh::policyHash(self::AUD, self::POLICY_A);
        $hashB = Pvh::policyHash(self::AUD, self::POLICY_AB);
        $nowMs = 1_700_000_000_123;
        $pvhA = Pvh::encode($nowMs, $hashA);
        $pvhB = Pvh::encode($nowMs, $hashB);

        $this->assertNotSame($pvhA, $pvhB);
        $this->assertSame($nowMs, Pvh::timestampMs($pvhA));
        $this->assertSame($nowMs, Pvh::timestampMs($pvhB));
        $this->assertNotSame(Pvh::hashPrefixHex($pvhA), Pvh::hashPrefixHex($pvhB));
    }
}
