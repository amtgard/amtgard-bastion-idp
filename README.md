# amtgard-bastion-idp
A bastion IDP that allows users to create accounts associated with Amtgard metadata and log into Amtgard digital properties

## Development
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

## Supported Clients
Clients are manually onboarded as of now. If you want to onboard a new client, contact Megiddo.

The IDP supports standard OAuth2 clients (Confidential and Public).
- **ORK Service**: deeply integrated for fetching player profiles.
- **Generic OAuth2 Clients**: Any compliant OAuth2 client can be registered in the `clients` database table.

## OAuth Server Operations
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