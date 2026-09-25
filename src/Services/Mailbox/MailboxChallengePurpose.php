<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services\Mailbox;

final class MailboxChallengePurpose
{
    public const CLAIM_ORK = 'claim_ork';
    public const CLAIM_IDP = 'claim_idp';
    public const MIGRATE_IDP_EMAIL = 'migrate_idp_email';
    public const MIGRATE_ORK_EMAIL = 'migrate_ork_email';
    public const CONFIRM_FIRST_EMAIL = 'confirm_first_email';

    public const STAGE_PENDING_CURRENT = 'pending_current';
    public const STAGE_PENDING_NEW = 'pending_new';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::CLAIM_ORK,
            self::CLAIM_IDP,
            self::MIGRATE_IDP_EMAIL,
            self::MIGRATE_ORK_EMAIL,
            self::CONFIRM_FIRST_EMAIL,
        ];
    }

    public static function isKnown(string $purpose): bool
    {
        return in_array($purpose, self::all(), true);
    }
}
