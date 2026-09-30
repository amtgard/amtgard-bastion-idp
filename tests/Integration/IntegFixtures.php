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

    public const UI_CREATED_CLIENT_ID = 'integ_from_ui';
    public const UI_CREATED_REDIRECT_URI = 'http://localhost:37080/integ/from-ui/callback';
    public const UI_UPDATED_REDIRECT_URI = 'http://localhost:37080/integ/from-ui/updated';

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
}
