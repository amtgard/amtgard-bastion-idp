<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IdP\Utility\Pvh;
use Amtgard\IdP\Utility\PvhAccess;
use Amtgard\IdP\Utility\PvhCacheRecord;
use Amtgard\IdP\Utility\PvhGate;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;

class PvhGateTest extends TestCase
{
    private const USER = 'user-123';
    private const AUD = 'client-1';
    private const EMAIL = 'test@example.com';
    private const POLICY = '[]';

    public function testEvaluateMissWhenCacheEmpty(): void
    {
        $this->assertSame(
            PvhAccess::Miss,
            PvhGate::evaluate(null, ['pvh' => $this->samplePvh()])
        );
    }

    public function testEvaluateCurrentOnPvhMatch(): void
    {
        $pvh = $this->samplePvh();
        $cached = $this->record($pvh, null);

        $this->assertSame(PvhAccess::Current, PvhGate::evaluate($cached, ['pvh' => $pvh]));
    }

    public function testEvaluatePreviousOnPrevPvhMatch(): void
    {
        $current = $this->samplePvh(1_700_000_000_001);
        $prev = $this->samplePvh(1_700_000_000_000);
        $cached = $this->record($current, $prev);

        $this->assertSame(PvhAccess::Previous, PvhGate::evaluate($cached, ['pvh' => $prev]));
    }

    public function testEvaluateUnknownWhenNeitherMatches(): void
    {
        $cached = $this->record($this->samplePvh(1_700_000_000_001), $this->samplePvh(1_700_000_000_000));

        $this->assertSame(
            PvhAccess::Unknown,
            PvhGate::evaluate($cached, ['pvh' => $this->samplePvh(1_800_000_000_000)])
        );
    }

    public function testEvaluateFatJwtHashPrefixMatchesCurrent(): void
    {
        $hash = Pvh::policyHash(self::AUD, self::POLICY, '');
        $cached = $this->record(Pvh::encode(1_700_000_000_000, $hash), null);

        $this->assertSame(
            PvhAccess::Current,
            PvhGate::evaluate($cached, ['aud' => self::AUD, 'policy' => self::POLICY])
        );
    }

    public function testEvaluateFatJwtHashPrefixMatchesPrevious(): void
    {
        $currentHash = Pvh::policyHash(self::AUD, '["newer"]', '');
        $prevHash = Pvh::policyHash(self::AUD, self::POLICY, '');
        $cached = $this->record(
            Pvh::encode(1_700_000_000_001, $currentHash),
            Pvh::encode(1_700_000_000_000, $prevHash)
        );

        $this->assertSame(
            PvhAccess::Previous,
            PvhGate::evaluate($cached, ['aud' => self::AUD, 'policy' => self::POLICY])
        );
    }

    public function testStaleTokenResponseIs409Json(): void
    {
        $response = PvhGate::staleTokenResponse();

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame(['error' => 'stale_token'], json_decode((string) $response->getBody(), true));
    }

    public function testWriteStaleTokenUsesProvidedResponse(): void
    {
        $response = (new ResponseFactory())->createResponse();
        $result = PvhGate::writeStaleToken($response);

        $this->assertSame(409, $result->getStatusCode());
        $this->assertSame(['error' => 'stale_token'], json_decode((string) $result->getBody(), true));
    }

    private function record(string $pvh, ?string $prevPvh): PvhCacheRecord
    {
        return new PvhCacheRecord(self::USER, self::AUD, self::EMAIL, $pvh, $prevPvh);
    }

    private function samplePvh(int $nowMs = 1_700_000_000_000): string
    {
        return Pvh::encode($nowMs, Pvh::policyHash(self::AUD, self::POLICY, ''));
    }
}
