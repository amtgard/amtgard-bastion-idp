<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services\Ork;

final class OrkClaimMailViaOrkFeature
{
    public static function enabled(): bool
    {
        $raw = $_ENV['ORK_CLAIM_MAIL_VIA_ORK'] ?? '';

        return $raw === '1' || strtolower($raw) === 'true';
    }
}
