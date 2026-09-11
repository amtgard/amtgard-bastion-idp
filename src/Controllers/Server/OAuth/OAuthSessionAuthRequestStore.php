<?php

declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Server\OAuth;

use League\OAuth2\Server\RequestTypes\AuthorizationRequest;

final class OAuthSessionAuthRequestStore
{
    public function hasAuthRequest(): bool
    {
        return array_key_exists('authRequest', $_SESSION);
    }

    public function load(): ?AuthorizationRequest
    {
        if (!$this->hasAuthRequest()) {
            return null;
        }

        /** @var AuthorizationRequest $authRequest */
        $authRequest = unserialize($_SESSION['authRequest']);

        return $authRequest;
    }

    public function store(AuthorizationRequest $authRequest): void
    {
        $_SESSION['authRequest'] = serialize($authRequest);
    }

    public function clearAuthRequest(): void
    {
        if (isset($_SESSION['authRequest'])) {
            unset($_SESSION['authRequest']);
        }
    }

    public function isApproved(): bool
    {
        return array_key_exists('approved', $_SESSION);
    }

    public function markApproved(): void
    {
        $_SESSION['approved'] = true;
    }

    public function clearApproval(): void
    {
        if (isset($_SESSION['approved'])) {
            unset($_SESSION['approved']);
        }
    }

    public function clearAuthorizationState(): void
    {
        $this->clearAuthRequest();
        $this->clearApproval();
    }

    public function sessionUserId(): ?string
    {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }

        return (string) $_SESSION['user_id'];
    }

    public function clearSessionUserId(): void
    {
        if (isset($_SESSION['user_id'])) {
            unset($_SESSION['user_id']);
        }
    }
}
