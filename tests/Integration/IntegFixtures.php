<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

/** Shared fixture identifiers for integration seed data and later milestones. */
final class IntegFixtures
{
    public const PLAYER_EMAIL = 'integ-player@example.com';
    public const ADMIN_EMAIL = 'integ-admin@example.com';
    public const PASSWORD = 'integ-fixture-pass';
    public const CONFIDENTIAL_CLIENT_ID = 'integ_confidential';
    public const CONFIDENTIAL_CLIENT_SECRET = 'integ-confidential-secret';
    public const CONFIDENTIAL_REDIRECT_URI = 'http://localhost:37080/integ/callback';
}
