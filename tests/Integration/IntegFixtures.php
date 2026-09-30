<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

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

    public const UI_CREATED_CLIENT_ID = 'integ_from_ui';
    public const UI_CREATED_REDIRECT_URI = 'http://localhost:37080/integ/from-ui/callback';
    public const UI_UPDATED_REDIRECT_URI = 'http://localhost:37080/integ/from-ui/updated';

    /** Matches `IDP_ORK_SHARED_SECRET` in docker/compose.integ.yml for connect JWT minting. */
    public const ORK_SHARED_SECRET = 'integ-ork-shared-secret-thirty-two-chars';

    public const ORK_LINK_PASSWORD = 'integ-ork-canned-password';
}
