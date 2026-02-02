# amtgard-bastion-idp
Amtgard Identity Provider (https://idp.amtgard.com) provides identity services for digital services (apps) for Amtgard and related boffer activities.

The basic concept is that Amtgard IDP converts your online account (such as google or facebook) into an Amtgard account. This account is shared across Amtgard apps such as the ORK, event management apps, and online forums.

The benefit of this is a single unified digital Amtgard account across apps and websites.

## Onboarding Your App

If you want to use the Amtgard IDP to manage authentication and authorization for you website or app, you will need an Amtgard IDP client configured. Right now, this is a manual process - please contact Megiddo to request access.

The IDP supports standard OAuth2 clients (Confidential and Public).

Confidential clients are clients where a *Client Secret* can be kept private and secure. Examples are websites where the client secret is kept secret and secure on the web server.

Public clients are client where the application lives entirely in the browser (aka SPA) or is installed on the user's device (such as a phone app).

- **ORK Service**: deeply integrated for fetching player profiles.
- **Generic OAuth2 Clients**: Any compliant OAuth2 client can be registered in the `clients` database table.

If you need onboarding help, please reach out. We host several example implementations on github for your reference: https://github.com/amtgard/amtgard-idp-client-examples

## OAuth Server Operations

The OAuth Server offers several resources:
* **User Info**: email, persona, and ORK-related information. This information can and should be stored and cached locally. This endpoint is rate limited at a relatively low level.
* **

The IDP provides specific endpoints for retrieving user data and validating sessions.

### User Info Endpoint
**Endpoint**: `/resources/userinfo`
- **Purpose**: Retrieves the full profile of the authenticated user.
- **Use Case**: Used by clients (like the ORK or a user profile page) to display user details, including linked Amtgard ORK profile data (Mundane ID, Persona, Park, Kingdom, etc.).
- **Response**: JSON object containing `id`, `email`, and `ork_profile` (if linked).

### Validate Endpoint
**Endpoint**: `/resources/validate` (or `/oauth/validate`)
- **Purpose**: A lightweight endpoint to quickly validate an Access Token and register "liveness".
- **Use Case**: Used by clients to check if a user's session is still active without fetching the full profile.
- **Behavior**:
  - Checks if the user is in the Redis cache.
  - Triggers a PubSub event to notify other services that the user is online/active.
  - Returns minimal user data (`id`, `email`).
- **Differentiation**: unlike `userinfo`, `validate` is optimized for high-frequency "heartbeat" checks and presence tracking.

## Development

This project requires extensive configuration via dotenv (.env). Check .dev.env and .env.example for details.

```php
composer install
vendor/robmorgan/phinx/bin/phinx migrate
sudo docker-compose -f docker-compose.dev.yml up -d --build
```

Server will be on http://localhost:37080/

## Configuration
Copy the example environment file to get started:
```bash
cp .env.example .env
```

### Key Configuration Options
- **Application**: `APP_URL`, `APP_ENV`, `APP_SECRET`
- **Database**: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
- **OAuth**:
  - `OAUTH_PRIVATE_KEY` / `OAUTH_PUBLIC_KEY`: Paths to RSA keys for signing tokens.
  - `OAUTH_ENCRYPTION_KEY`: Key for encrypting auth codes.
- **Social Login**: Credentials for Google, Facebook, Discord (`GOOGLE_CLIENT_ID`, etc.)

