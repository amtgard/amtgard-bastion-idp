<?php

declare(strict_types=1);

namespace Amtgard\IdP\Models\Oidc;

use Amtgard\IdP\Persistence\Client\Entities\UserEntity;

final class OidcClaimFactory
{
    /**
     * Identity claims for an id_token or UserInfo body. Empty keys are omitted.
     *
     * @return array<string, int|string>
     */
    public static function fromUser(UserEntity $user): array
    {
        $claims = [];

        $sub = self::nonEmptyString($user->getUserId());
        if ($sub !== null) {
            $claims['sub'] = $sub;
        }

        $email = self::nonEmptyString($user->getEmail());
        if ($email !== null) {
            $claims['email'] = $email;
        }

        $name = self::displayName($user->getFirstName(), $user->getLastName());
        if ($name !== null) {
            $claims['name'] = $name;
        }

        $preferredUsername = self::nonEmptyString($user->getUsername());
        if ($preferredUsername !== null) {
            $claims['preferred_username'] = $preferredUsername;
        }

        $updatedAt = $user->getUpdatedAt();
        if ($updatedAt !== null) {
            $claims['updated_at'] = $updatedAt->getTimestamp();
        }

        return $claims;
    }

    private static function displayName(?string $firstName, ?string $lastName): ?string
    {
        $parts = [];

        $first = self::nonEmptyString($firstName);
        if ($first !== null) {
            $parts[] = $first;
        }

        $last = self::nonEmptyString($lastName);
        if ($last !== null) {
            $parts[] = $last;
        }

        if ($parts === []) {
            return null;
        }

        return implode(' ', $parts);
    }

    private static function nonEmptyString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }
}
