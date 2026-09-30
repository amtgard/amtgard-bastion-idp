<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Models\Oidc;

use Amtgard\IdP\Models\Oidc\OidcClaimFactory;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use DateTime;
use PHPUnit\Framework\TestCase;

class OidcClaimFactoryTest extends TestCase
{
    public function testFromUserMapsIdentityClaims(): void
    {
        $updatedAt = (new DateTime())->setTimestamp(1_700_000_000);
        $user = $this->user(
            userId: 'uuid-1',
            email: 'user@example.com',
            firstName: 'Ada',
            lastName: 'Lovelace',
            username: 'ada',
            updatedAt: $updatedAt,
        );

        $this->assertSame(
            [
                'sub' => 'uuid-1',
                'email' => 'user@example.com',
                'name' => 'Ada Lovelace',
                'preferred_username' => 'ada',
                'updated_at' => 1_700_000_000,
            ],
            OidcClaimFactory::fromUser($user)
        );
    }

    public function testEmptyNamesOmitName(): void
    {
        $user = $this->user(firstName: '', lastName: '');

        $claims = OidcClaimFactory::fromUser($user);

        $this->assertArrayNotHasKey('name', $claims);
    }

    public function testNullNamesOmitName(): void
    {
        $user = $this->user(firstName: null, lastName: null);

        $this->assertArrayNotHasKey('name', OidcClaimFactory::fromUser($user));
    }

    public function testWhitespaceNamesOmitName(): void
    {
        $user = $this->user(firstName: '   ', lastName: "\t");

        $this->assertArrayNotHasKey('name', OidcClaimFactory::fromUser($user));
    }

    public function testFirstNameAloneBecomesName(): void
    {
        $user = $this->user(firstName: 'Ada', lastName: '');

        $this->assertSame('Ada', OidcClaimFactory::fromUser($user)['name']);
        $this->assertSame('Ada', OidcClaimFactory::fromUser($this->user(firstName: 'Ada', lastName: null))['name']);
    }

    public function testLastNameAloneBecomesName(): void
    {
        $user = $this->user(firstName: '', lastName: 'Lovelace');

        $this->assertSame('Lovelace', OidcClaimFactory::fromUser($user)['name']);
        $this->assertSame('Lovelace', OidcClaimFactory::fromUser($this->user(firstName: null, lastName: 'Lovelace'))['name']);
    }

    public function testNameTrimsAndJoinsParts(): void
    {
        $user = $this->user(firstName: '  Ada  ', lastName: '  Lovelace  ');

        $this->assertSame('Ada Lovelace', OidcClaimFactory::fromUser($user)['name']);
    }

    public function testMissingEmailOmitsEmail(): void
    {
        $this->assertArrayNotHasKey('email', OidcClaimFactory::fromUser($this->user(email: null)));
        $this->assertArrayNotHasKey('email', OidcClaimFactory::fromUser($this->user(email: '')));
        $this->assertArrayNotHasKey('email', OidcClaimFactory::fromUser($this->user(email: '   ')));
    }

    public function testEmailIsTrimmed(): void
    {
        $user = $this->user(email: '  user@example.com  ');

        $this->assertSame('user@example.com', OidcClaimFactory::fromUser($user)['email']);
    }

    public function testMissingUsernameOmitsPreferredUsername(): void
    {
        $this->assertArrayNotHasKey('preferred_username', OidcClaimFactory::fromUser($this->user(username: null)));
        $this->assertArrayNotHasKey('preferred_username', OidcClaimFactory::fromUser($this->user(username: '')));
        $this->assertArrayNotHasKey('preferred_username', OidcClaimFactory::fromUser($this->user(username: '   ')));
    }

    public function testUsernameIsTrimmed(): void
    {
        $user = $this->user(username: '  ada  ');

        $this->assertSame('ada', OidcClaimFactory::fromUser($user)['preferred_username']);
    }

    public function testMissingUserIdOmitsSub(): void
    {
        $this->assertArrayNotHasKey('sub', OidcClaimFactory::fromUser($this->user(userId: null)));
        $this->assertArrayNotHasKey('sub', OidcClaimFactory::fromUser($this->user(userId: '')));
        $this->assertArrayNotHasKey('sub', OidcClaimFactory::fromUser($this->user(userId: '   ')));
    }

    public function testUserIdIsTrimmed(): void
    {
        $user = $this->user(userId: '  uuid-1  ');

        $this->assertSame('uuid-1', OidcClaimFactory::fromUser($user)['sub']);
    }

    public function testMissingUpdatedAtOmitsUpdatedAt(): void
    {
        $this->assertArrayNotHasKey('updated_at', OidcClaimFactory::fromUser($this->user(updatedAt: null)));
    }

    public function testUpdatedAtZeroTimestampIsKept(): void
    {
        $updatedAt = (new DateTime())->setTimestamp(0);
        $user = $this->user(updatedAt: $updatedAt);

        $this->assertSame(0, OidcClaimFactory::fromUser($user)['updated_at']);
        $this->assertArrayHasKey('updated_at', OidcClaimFactory::fromUser($user));
    }

    public function testClaimsOmitAuthorizationAndUnverifiedKeys(): void
    {
        $user = $this->user();
        $claims = OidcClaimFactory::fromUser($user);

        $this->assertArrayNotHasKey('email_verified', $claims);
        $this->assertArrayNotHasKey('picture', $claims);
        $this->assertArrayNotHasKey('orkid', $claims);
        $this->assertArrayNotHasKey('policy', $claims);
        $this->assertArrayNotHasKey('pvh', $claims);
        $this->assertArrayNotHasKey('orkuser', $claims);
        $this->assertArrayNotHasKey('client_metadata', $claims);
        $this->assertArrayNotHasKey('given_name', $claims);
        $this->assertArrayNotHasKey('family_name', $claims);
        $this->assertSame(['sub', 'email', 'name', 'preferred_username'], array_keys($claims));
    }

    private function user(
        ?string $userId = 'uuid-1',
        ?string $email = 'user@example.com',
        ?string $firstName = 'Ada',
        ?string $lastName = 'Lovelace',
        ?string $username = 'ada',
        ?DateTime $updatedAt = null,
    ): UserEntity {
        return new class($userId, $email, $firstName, $lastName, $username, $updatedAt) extends UserEntity {
            public function __construct(
                private ?string $testUserId,
                private ?string $testEmail,
                private ?string $testFirstName,
                private ?string $testLastName,
                private ?string $testUsername,
                private ?DateTime $testUpdatedAt,
            ) {
            }

            public function getUserId(): ?string
            {
                return $this->testUserId;
            }

            public function getEmail(): ?string
            {
                return $this->testEmail;
            }

            public function getFirstName(): ?string
            {
                return $this->testFirstName;
            }

            public function getLastName(): ?string
            {
                return $this->testLastName;
            }

            public function getUsername(): ?string
            {
                return $this->testUsername;
            }

            public function getUpdatedAt(): ?DateTime
            {
                return $this->testUpdatedAt;
            }
        };
    }
}
