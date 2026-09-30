<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

use Firebase\JWT\JWT;

/** Shared fixture identifiers for integration seed data and later milestones. */
final class IntegFixtures
{
    public const PLAYER_EMAIL = 'integ-player@example.com';
    public const ADMIN_EMAIL = 'integ-admin@example.com';
    public const APPLE_EMAIL = 'integ-apple@example.com';
    public const PASSWORD = 'integ-fixture-pass';
    public const CONFIDENTIAL_CLIENT_ID = 'integ_confidential';
    public const CONFIDENTIAL_CLIENT_SECRET = 'integ-confidential-secret';
    public const CONFIDENTIAL_REDIRECT_URI = 'http://localhost:37080/integ/callback';

    /** Confidential client with no iam_service (credential-only routes). */
    public const NO_IAM_CLIENT_ID = 'integ_no_iam';
    public const NO_IAM_CLIENT_SECRET = 'integ-no-iam-secret';

    /** Second IAM namespace client for cross-client isolation integ (D8). */
    public const PEER_IAM_CLIENT_ID = 'integ_confidential_peer';
    public const PEER_IAM_CLIENT_SECRET = 'integ-confidential-peer-secret';
    public const PEER_IAM_SERVICE = 'IntegPeer';
    public const PEER_IAM_REDIRECT_URI = 'http://localhost:37080/integ/peer/callback';

    public const UI_CREATED_CLIENT_ID = 'integ_from_ui';
    public const UI_CREATED_REDIRECT_URI = 'http://localhost:37080/integ/from-ui/callback';
    public const UI_UPDATED_REDIRECT_URI = 'http://localhost:37080/integ/from-ui/updated';

    /** Admin management POST /management/clients/{id} (D7 integ). */
    public const UI_UPDATED_CLIENT_NAME = 'Integration UI client (updated)';
    public const UI_UPDATED_IAM_SERVICE = 'IntegFromUiUpdated';
    public const UI_UPDATED_IAM_FORMAT = 'Configuration,Game';

    /** Player operator list UI (D6 integ). */
    public const PLAYER_OPERATOR_CLIENT_ID = 'integ_player_operator';
    public const PLAYER_OPERATOR_REDIRECT_URI = 'http://localhost:37080/integ/player-operator/callback';

    /** Matches `MANAGEMENT_KEY` in docker/compose.integ.yml for cleantokens. */
    public const MANAGEMENT_KEY = 'integ-management-key-thirty-two-chars';

    /** Matches `IDP_ORK_SHARED_SECRET` in docker/compose.integ.yml for connect JWT minting. */
    public const ORK_SHARED_SECRET = 'integ-ork-shared-secret-thirty-two-chars';

    public const ORK_LINK_PASSWORD = 'integ-ork-canned-password';

    /** Base mundane id for connect-register integ (tests add random offset). */
    public const CONNECT_REGISTER_MUNDANE_ID = 91000;

    /** Mint an ORK→IDP connect handoff JWT (HS256, shared secret). */
    public static function mintConnectLinkToken(string $jti, string $email, int $mundaneId): string
    {
        return JWT::encode([
            'iss' => 'ork',
            'aud' => 'idp',
            'sub' => (string) $mundaneId,
            'email' => $email,
            'jti' => $jti,
            'iat' => time(),
            'exp' => time() + 900,
        ], self::ORK_SHARED_SECRET, 'HS256');
    }

    /** Mint a possession handoff JWT (Flow B with challenge_id + idp_email hint). */
    public static function mintPossessionConnectLinkToken(
        string $jti,
        string $idpEmail,
        int $mundaneId,
        string $orkChallengeId,
    ): string {
        return JWT::encode([
            'iss' => 'ork',
            'aud' => 'idp',
            'sub' => (string) $mundaneId,
            'idp_email' => $idpEmail,
            'challenge_id' => $orkChallengeId,
            'jti' => $jti,
            'iat' => time(),
            'exp' => time() + 900,
        ], self::ORK_SHARED_SECRET, 'HS256');
    }

    /** Mint an ORK→IDP Flow A completion JWT after claim_ork. */
    public static function mintFlowACompletionToken(
        string $challengeId,
        string $idpUserId,
        int $mundaneId,
        string $jti,
    ): string {
        $now = time();

        return JWT::encode([
            'iss' => 'ork',
            'aud' => 'idp',
            'challenge_id' => $challengeId,
            'idp_user_id' => $idpUserId,
            'mundane_id' => $mundaneId,
            'purpose' => 'claim_ork',
            'jti' => $jti,
            'iat' => $now,
            'exp' => $now + 300,
        ], self::ORK_SHARED_SECRET, 'HS256');
    }
}
