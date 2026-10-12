<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services\Mailbox;

/**
 * Structured, log-safe context for Flow A (profile → ORK mailbox code).
 * Raw addresses are hashed; full email is included only when APP_DEBUG=true.
 */
final class OrkFlowAMailDiagnostics
{
    /**
     * @param array{mundaneId: int, username: string, persona: string, parkName: string, kingdomName: string} $searchMatch
     * @param array<string, mixed>|null $player
     *
     * @return array<string, mixed>
     */
    public static function buildLogContext(
        int $idpUserId,
        string $submittedUsername,
        array $searchMatch,
        ?array $player,
        string $normalizedEmail,
    ): array {
        $mundaneId = (int) $searchMatch['mundaneId'];
        $blockReason = self::blockReason($player, $normalizedEmail);

        return [
            'idp_user_id' => $idpUserId,
            'submitted_username' => $submittedUsername,
            'mundane_id' => $mundaneId,
            'search_match' => [
                'username' => $searchMatch['username'],
                'persona' => $searchMatch['persona'],
                'park_name' => $searchMatch['parkName'],
                'kingdom_name' => $searchMatch['kingdomName'],
            ],
            'ork_player' => self::playerSnapshot($player),
            'ork_email_fields' => self::emailLogFields($normalizedEmail),
            'will_send' => $blockReason === null,
            'block_reason' => $blockReason,
        ];
    }

    /**
     * @param array<string, mixed>|null $player
     */
    public static function normalizeEmail(?array $player): string
    {
        if ($player === null) {
            return '';
        }

        return strtolower(trim((string) ($player['Email'] ?? '')));
    }

    /**
     * @param array<string, mixed>|null $player
     */
    public static function blockReason(?array $player, string $normalizedEmail): ?string
    {
        if ($player === null) {
            return 'player_missing';
        }
        if ($normalizedEmail === '') {
            return 'email_empty';
        }
        if (!str_contains($normalizedEmail, '@')) {
            return 'email_invalid';
        }
        if ((int) ($player['Suspended'] ?? 0) !== 0) {
            return 'suspended';
        }
        if (!empty($player['PenaltyBox'])) {
            return 'penalty_box';
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $player
     *
     * @return array<string, mixed>
     */
    public static function playerSnapshot(?array $player): array
    {
        if ($player === null) {
            return ['present' => false];
        }

        $emailRaw = trim((string) ($player['Email'] ?? ''));

        return [
            'present' => true,
            'mundane_id' => $player['MundaneId'] ?? null,
            'username' => $player['UserName'] ?? $player['username'] ?? null,
            'persona' => $player['Persona'] ?? null,
            'park_id' => $player['ParkId'] ?? null,
            'kingdom_id' => $player['KingdomId'] ?? null,
            'email_key_present' => array_key_exists('Email', $player),
            'email_raw_length' => strlen($emailRaw),
            'email_raw_has_at' => str_contains($emailRaw, '@'),
            'suspended' => $player['Suspended'] ?? null,
            'penalty_box' => $player['PenaltyBox'] ?? null,
            'show_email' => isset($player['ShowEmail']) ? (int) $player['ShowEmail'] : null,
            'restricted' => $player['Restricted'] ?? null,
        ];
    }

    /**
     * Profile redirect error query param for a skipped Flow A mail attempt.
     *
     * @param array<string, mixed>|null $player
     */
    public static function profileErrorForMailSkip(
        string $skipReason,
        ?array $player,
        ?string $blockReason,
    ): string {
        if ($skipReason === 'no_username') {
            return 'ork_username_not_found';
        }
        if ($skipReason === 'linked_elsewhere') {
            return 'ork_already_linked';
        }
        if ($blockReason === null) {
            return 'ork_mail_not_sent';
        }

        return match ($blockReason) {
            'player_missing' => 'ork_mailbox_lookup_failed',
            'suspended' => 'ork_player_suspended',
            'penalty_box' => 'ork_player_penalty_box',
            'email_invalid', 'email_empty' => self::emailEmptyProfileError($player),
            default => 'ork_mail_not_sent',
        };
    }

    /**
     * @param array<string, mixed>|null $player
     */
    private static function emailEmptyProfileError(?array $player): string
    {
        if ($player === null) {
            return 'ork_mailbox_lookup_failed';
        }
        if ((int) ($player['ShowEmail'] ?? 0) === 1) {
            return 'ork_mailbox_lookup_failed';
        }

        return 'ork_mailbox_not_set';
    }

    /**
     * @return array<string, mixed>
     */
    private static function emailLogFields(string $normalizedEmail): array
    {
        if ($normalizedEmail === '') {
            return [
                'sent_to_hash' => null,
                'ork_email_redacted' => null,
            ];
        }

        $fields = [
            'sent_to_hash' => hash('sha256', $normalizedEmail),
            'ork_email_redacted' => self::redactEmail($normalizedEmail),
        ];
        if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
            $fields['ork_email'] = $normalizedEmail;
        }

        return $fields;
    }

    public static function redactEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false) {
            return '***';
        }
        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        if ($local === '') {
            return '***@' . $domain;
        }
        $visible = substr($local, 0, min(2, strlen($local)));

        return $visible . '***@' . $domain;
    }
}
