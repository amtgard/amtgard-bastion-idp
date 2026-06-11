# Amtgard Identity Provider Integration Guide

Welcome to the Amtgard Identity Provider (IdP) developer documentation. The Amtgard IdP provides secure, unified authentication and user profile access for Amtgard web applications and services.

> [!TIP]
> **Interactive API Sandbox**: You can explore and test the endpoints directly using our <a href="/swagger" target="_self">Interactive Swagger UI</a> (or access the raw <a href="/openapi.json" target="_self">OpenAPI Specification JSON</a>).

---

## 1. Getting an Access Token

If you're new to OAuth, don't worry! Getting an Access Token is a standard, straightforward process. The Amtgard IdP is fully standards-compliant, meaning you don't need to write complex authentication flows yourself—you can use standard libraries (like the ones shown below) to handle everything.

### Step A: Request a Client ID & Secret
Before your application can communicate with the IdP, you need credentials (a `Client ID` and a `Client Secret`).
To get these:
- Ask the administration/maintainers directly on the **ORK Discord** server.
- Or post a request in the **Facebook ORK Help & Updates** group.

### Step B: How Your App Gets the Token
Once you have your credentials, the OAuth flow works as follows:
1. Your app redirects the user's browser to the Amtgard IdP authorization page.
2. The user signs in and grants permission.
3. The user is redirected back to your app with an authorization code.
4. Your server-side code sends that code, along with your `Client Secret`, back to the IdP.
5. The IdP responds with your **Access Token**.

You will pass this token in the header of all your API requests to retrieve user details.

---

## 2. API Endpoint Reference

These are the HTTP endpoints your application calls after you have registered an OAuth client. The Amtgard IdP implements standard OAuth 2.0 (authorization code + PKCE) plus a small set of resource endpoints for profile data and session validation.

### OAuth 2.0 Server

#### Authorization Endpoint (`GET /oauth/authorize`)

Starts the login and consent flow. Redirect the user's browser here with standard OAuth query parameters.

| Parameter | Required | Description |
|-----------|----------|-------------|
| `response_type` | Yes | Must be `code` |
| `client_id` | Yes | Your registered client identifier |
| `redirect_uri` | Yes | Must match a URI registered for your client |
| `scope` | Yes | Space-separated scopes (e.g. `profile email`) |
| `state` | Yes | Random value you verify on callback (CSRF protection) |
| `code_challenge` | Yes (public clients) | PKCE S256 challenge |
| `code_challenge_method` | Yes (public clients) | Must be `S256` |

If the user is not logged in, they are redirected to `/auth/login` and returned here afterward. If the user has not previously authorized your client, they see a consent screen at `/oauth/approve`. On success, the user is redirected to your `redirect_uri` with an authorization `code`.

#### Token Endpoint (`POST /oauth/token`)

Exchange an authorization code or refresh token for access (and optionally refresh) tokens. Authenticate confidential clients with `client_id` and `client_secret` in the request body.

**Authorization code exchange** (after user returns from `/oauth/authorize`):

| Field | Description |
|-------|-------------|
| `grant_type` | `authorization_code` |
| `client_id` | Your client identifier |
| `client_secret` | Required for confidential clients |
| `redirect_uri` | Must match the authorize request |
| `code` | Authorization code from the redirect |
| `code_verifier` | PKCE verifier (public clients) |

**Refresh token exchange**:

| Field | Description |
|-------|-------------|
| `grant_type` | `refresh_token` |
| `client_id` | Your client identifier |
| `client_secret` | Required for confidential clients |
| `refresh_token` | Previously issued refresh token |

**Example response**:

```json
{
  "token_type": "Bearer",
  "expires_in": 7200,
  "access_token": "...",
  "refresh_token": "..."
}
```

---

### Resource Endpoints

After OAuth login, profile and IAM data use a **two-step elevation**:

1. **`GET /resources/jwt`** — present your OAuth **access token** (or browser session) to obtain a signed RS256 **authorization JWT**.
2. **`GET /resources/userinfo`** — present that **authorization JWT** (not the access token) to load the full profile.

```http
Authorization: Bearer <authorization_jwt>
```

Browser-first-party apps may use the session cookie for step 1 instead of an access token.

#### <a href="/swagger#/default/getJwt" target="_self">Authorization JWT (`GET /resources/jwt`)</a>

Elevates an OAuth access token (or authenticated session) to a signed RS256 authorization JWT containing IAM policy and optional `client_metadata`.

- **Method**: `GET`
- **Auth**: `Authorization: Bearer <access_token>` from `/oauth/token`, or session cookie for browser apps
- **Response Format**: `application/json`
- **Use when**: You need the authorization JWT before calling `/resources/userinfo` or `/resources/validate`

- **Example Response**:

```json
{
  "jwt": "eyJ..."
}
```

#### <a href="/swagger#/default/userinfo" target="_self">User Info (`GET /resources/userinfo`)</a>

Retrieves the full profile of the authenticated user, including their linked Amtgard ORK profile (Mundane ID, persona, kingdom, park, image, dues status, etc.). This is the primary endpoint for loading user data after login.

- **Method**: `GET`
- **Auth**: `Authorization: Bearer <authorization_jwt>` from `/resources/jwt` — **not** the OAuth access token
- **Response Format**: `application/json`
- **Use when**: You need complete profile data — display name, email, ORK persona, park/kingdom, dues, heraldry, etc.

The `jwt` field is the same RS256 **authorization JWT** you sent in the `Authorization` header (refreshed if needed). Decode it to access IAM policy and optional client metadata (see [Section 8](#8-client-iam--jwt-metadata-server-to-server)). Claims include:

| Claim | Description |
|-------|-------------|
| `sub` | IDP user UUID |
| `aud` | OAuth `client_id` of the requesting app |
| `email`, `orkid`, `orkuser` | Identity fields |
| `policy` | User's IAM policy (ORN JSON) — IDP is the authoritative policy store |
| `client_metadata` | Optional per-user JSON blob (≤ 200 bytes) set by the requesting client |

- **Example Response**:

```json
{
  "id": 123,
  "email": "player@amtgard.com",
  "jwt": "...",
  "ork_profile": {
    "mundane_id": 456,
    "username": "Amtgardian",
    "persona": "Dread Knight Megiddo",
    "suspended": false,
    "suspended_at": null,
    "suspended_until": null,
    "park_id": 12,
    "park_name": "Tor de Gracia",
    "kingdom_id": 3,
    "kingdom_name": "Emerald Hills",
    "image": "https://...",
    "heraldry": "https://...",
    "dues_through": "2027-12-31"
  }
}
```

#### <a href="/swagger#/default/validate" target="_self">Validate Token & Presence (`GET /resources/validate`)</a>

A lightweight endpoint to confirm a session is still active and register user presence (heartbeat/liveness). Returns minimal identity data compared to `userinfo`.

- **Method**: `GET`
- **Auth**: `Authorization: Bearer <authorization_jwt>` from `/resources/jwt`
- **Response Format**: `application/json`
- **Use when**: You need frequent, low-cost checks that a user is still online — for example presence indicators or activity heartbeats — without fetching the full profile each time.
- **Note**: Also publishes a presence event to connected services via PubSub.
- **Example Response**:

```json
{
  "id": 123,
  "email": "player@amtgard.com",
  "jwt": "..."
}
```

---

### Policy Evaluation (`POST /api/is_authorized`)

Used by other Amtgard backend services to evaluate IAM authorization policies. This is **not** part of the standard OAuth client integration flow; most app developers will not call this directly.

- **Method**: `POST`
- **Content-Type**: `application/json` or form body
- **Request body**:
  - `policy` — JSON array representing the user's IAM policy (ORN format)
  - `requirement` — Requirement string to check (e.g. `Idp:0::::IDP/SomeAction`)
- **Response**:

```json
{ "is_authorized": true }
```

Contact the IDP maintainers if your service needs to integrate with the policy engine.

---

## 3. Implementation Code Examples

Select your programming language to see implementation examples for each stage of the OAuth 2.0 integration.

### A. PKCE Generation & Redirecting to Login
Generate the PKCE code verifier, hash it using SHA256 to create the challenge, and redirect the user.

<!-- tabs:start -->

#### **PHP**
```php
session_start();

// Helper functions for URL encoding and PKCE
function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

$verifier = base64UrlEncode(random_bytes(32));
$challenge = base64UrlEncode(hash('sha256', $verifier, true));
$state = bin2hex(random_bytes(16));

$_SESSION['oauth_verifier'] = $verifier;
$_SESSION['oauth_state'] = $state;

$params = [
    'response_type' => 'code',
    'client_id' => 'your_amtgard_idp_client',
    'redirect_uri' => 'https://your-app.com/callback',
    'scope' => 'profile email',
    'state' => $state,
    'code_challenge' => $challenge,
    'code_challenge_method' => 'S256',
    'approval_prompt' => 'auto'
];

$authUrl = 'https://idp.amtgard.com/oauth/authorize?' . http_build_query($params);
header('Location: ' . $authUrl);
exit;
```

#### **JavaScript (Node.js / Express)**
```javascript
import crypto from 'crypto';

// Express login route handler
app.get('/login', (req, res) => {
  // Helper to base64url encode buffers
  const base64UrlEncode = (str) => {
    return str.toString('base64')
      .replace(/\+/g, '-')
      .replace(/\//g, '_')
      .replace(/=/g, '');
  };

  const verifier = base64UrlEncode(crypto.randomBytes(32));
  const challenge = base64UrlEncode(crypto.createHash('sha256').update(verifier).digest());
  const state = crypto.randomBytes(16).toString('hex');

  // Store verifier and state in session
  req.session.oauth_verifier = verifier;
  req.session.oauth_state = state;

  const params = new URLSearchParams({
    response_type: 'code',
    client_id: 'your_amtgard_idp_client',
    redirect_uri: 'https://your-app.com/callback',
    scope: 'profile email',
    state: state,
    code_challenge: challenge,
    code_challenge_method: 'S256',
    approval_prompt: 'auto'
  });

  res.redirect(`https://idp.amtgard.com/oauth/authorize?${params.toString()}`);
});
```

#### **TypeScript (Node.js)**
```typescript
import { Request, Response } from 'express';
import * as crypto from 'crypto';

app.get('/login', (req: Request, res: Response): void => {
  const base64UrlEncode = (buf: Buffer): string => {
    return buf.toString('base64')
      .replace(/\+/g, '-')
      .replace(/\//g, '_')
      .replace(/=/g, '');
  };

  const verifier: string = base64UrlEncode(crypto.randomBytes(32));
  const challenge: string = base64UrlEncode(crypto.createHash('sha256').update(verifier).digest());
  const state: string = crypto.randomBytes(16).toString('hex');

  req.session.oauth_verifier = verifier;
  req.session.oauth_state = state;

  const params = new URLSearchParams({
    response_type: 'code',
    client_id: 'your_amtgard_idp_client',
    redirect_uri: 'https://your-app.com/callback',
    scope: 'profile email',
    state,
    code_challenge: challenge,
    code_challenge_method: 'S256',
    approval_prompt: 'auto'
  });

  res.redirect(`https://idp.amtgard.com/oauth/authorize?${params.toString()}`);
});
```

<!-- tabs:end -->

---

### B. Exchanging Code for Access Token
Swap the returned authorization code along with your stored PKCE verifier for the final JSON payload containing the access token.

<!-- tabs:start -->

#### **PHP**
```php
session_start();

if (!isset($_GET['code']) || $_GET['state'] !== $_SESSION['oauth_state']) {
    die('Invalid state or parameters.');
}

$code = $_GET['code'];
$verifier = $_SESSION['oauth_verifier'];

$tokenUrl = 'https://idp.amtgard.com/oauth/token';
$postData = [
    'grant_type' => 'authorization_code',
    'client_id' => 'your_amtgard_idp_client',
    'client_secret' => 'your_client_secret',
    'redirect_uri' => 'https://your-app.com/callback',
    'code_verifier' => $verifier,
    'code' => $code,
];

$ch = curl_init($tokenUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
$response = curl_exec($ch);
$tokenData = json_decode($response, true);
curl_close($ch);

$accessToken = $tokenData['access_token'];
$refreshToken = $tokenData['refresh_token'] ?? null;
```

#### **JavaScript (Node.js)**
```javascript
app.get('/callback', async (req, res) => {
  const { code, state } = req.query;

  if (!code || state !== req.session.oauth_state) {
    return res.status(400).send('Invalid state or state mismatch.');
  }

  const verifier = req.session.oauth_verifier;

  try {
    const response = await fetch('https://idp.amtgard.com/oauth/token', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        grant_type: 'authorization_code',
        client_id: 'your_amtgard_idp_client',
        client_secret: 'your_client_secret',
        redirect_uri: 'https://your-app.com/callback',
        code_verifier: verifier,
        code: code
      })
    });

    const tokenData = await response.json();
    req.session.access_token = tokenData.access_token;
    req.session.refresh_token = tokenData.refresh_token;
    
    res.redirect('/profile');
  } catch (error) {
    res.status(500).send('Token exchange failed.');
  }
});
```

#### **TypeScript (Node.js)**
```typescript
interface TokenResponse {
  access_token: string;
  refresh_token?: string;
  expires_in: number;
  token_type: string;
}

app.get('/callback', async (req: Request, res: Response) => {
  const { code, state } = req.query as { code?: string; state?: string };

  if (!code || state !== req.session.oauth_state) {
    return res.status(400).send('State validation failed');
  }

  const verifier = req.session.oauth_verifier;

  try {
    const response = await fetch('https://idp.amtgard.com/oauth/token', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        grant_type: 'authorization_code',
        client_id: 'your_amtgard_idp_client',
        client_secret: 'your_client_secret',
        redirect_uri: 'https://your-app.com/callback',
        code_verifier: verifier,
        code
      })
    });

    const tokenData = await response.json() as TokenResponse;
    req.session.access_token = tokenData.access_token;
    req.session.refresh_token = tokenData.refresh_token;

    res.redirect('/profile');
  } catch (error) {
    res.status(500).send('Token exchange failed');
  }
});
```

<!-- tabs:end -->

---

### C. Fetching User Profile Details
Exchange the OAuth access token for an authorization JWT, then call userinfo.

<!-- tabs:start -->

#### **PHP**
```php
function fetchAuthorizationJwt(string $accessToken): string
{
    $ch = curl_init('https://idp.amtgard.com/resources/jwt');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json',
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true)['jwt'];
}

$authJwt = fetchAuthorizationJwt($accessToken);

$ch = curl_init('https://idp.amtgard.com/resources/userinfo');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $authJwt,
    'Accept: application/json',
]);
$response = curl_exec($ch);
$profileData = json_decode($response, true);
curl_close($ch);

echo "Hello, " . $profileData['ork_profile']['persona'];
```

#### **JavaScript**
```javascript
async function fetchAuthorizationJwt(accessToken) {
  const response = await fetch('https://idp.amtgard.com/resources/jwt', {
    method: 'GET',
    headers: {
      'Authorization': `Bearer ${accessToken}`,
      'Accept': 'application/json',
    },
  });
  const data = await response.json();
  return data.jwt;
}

async function fetchUserProfile(accessToken) {
  const authJwt = await fetchAuthorizationJwt(accessToken);

  const response = await fetch('https://idp.amtgard.com/resources/userinfo', {
    method: 'GET',
    headers: {
      'Authorization': `Bearer ${authJwt}`,
      'Accept': 'application/json',
    },
  });

  const profile = await response.json();
  console.log(`Hello, ${profile.ork_profile?.persona}`);
  return profile;
}
```

#### **TypeScript**
```typescript
interface OrkProfile {
  mundane_id: number;
  username: string;
  persona: string;
  park_name: string;
  kingdom_name: string;
  dues_through: string;
}

interface UserProfile {
  id: number;
  email: string;
  jwt: string;
  ork_profile?: OrkProfile;
}

async function fetchAuthorizationJwt(accessToken: string): Promise<string> {
  const response = await fetch('https://idp.amtgard.com/resources/jwt', {
    method: 'GET',
    headers: {
      'Authorization': `Bearer ${accessToken}`,
      'Accept': 'application/json',
    },
  });
  const data = await response.json() as { jwt: string };
  return data.jwt;
}

async function fetchUserProfile(accessToken: string): Promise<UserProfile> {
  const authJwt = await fetchAuthorizationJwt(accessToken);

  const response = await fetch('https://idp.amtgard.com/resources/userinfo', {
    method: 'GET',
    headers: {
      'Authorization': `Bearer ${authJwt}`,
      'Accept': 'application/json',
    },
  });

  const profile = await response.json() as UserProfile;
  console.log(`Hello, ${profile.ork_profile?.persona}`);
  return profile;
}
```

<!-- tabs:end -->

---

### D. Refreshing the Access Token
Use your stored `refresh_token` to swap for a new valid access token.

<!-- tabs:start -->

#### **PHP**
```php
$postData = [
    'grant_type' => 'refresh_token',
    'client_id' => 'your_amtgard_idp_client',
    'client_secret' => 'your_client_secret',
    'refresh_token' => $storedRefreshToken,
];

$ch = curl_init('https://idp.amtgard.com/oauth/token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
$response = curl_exec($ch);
$tokenData = json_decode($response, true);
curl_close($ch);

$newAccessToken = $tokenData['access_token'];
```

#### **JavaScript**
```javascript
async function refreshAccessToken(storedRefreshToken) {
  const response = await fetch('https://idp.amtgard.com/oauth/token', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      grant_type: 'refresh_token',
      client_id: 'your_amtgard_idp_client',
      client_secret: 'your_client_secret',
      refresh_token: storedRefreshToken
    })
  });

  const tokenData = await response.json();
  return tokenData.access_token;
}
```

#### **TypeScript**
```typescript
interface RefreshResponse {
  access_token: string;
  refresh_token?: string;
  expires_in: number;
}

async function refreshAccessToken(storedRefreshToken: string): Promise<string> {
  const response = await fetch('https://idp.amtgard.com/oauth/token', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      grant_type: 'refresh_token',
      client_id: 'your_amtgard_idp_client',
      client_secret: 'your_client_secret',
      refresh_token: storedRefreshToken
    })
  });

  const tokenData = await response.json() as RefreshResponse;
  return tokenData.access_token;
}
```

<!-- tabs:end -->

---

## 4. Integration with PHP League's OAuth 2.0 Client (`league/oauth2-client`)

To simplify this implementation in PHP, use the standard [PHP League OAuth 2.0 Client](https://oauth2-client.thephpleague.com/) provider wrapper.

### Installation
```bash
composer require league/oauth2-client
```

### Complete Implementation Example

```php
<?php
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;

session_start();

$provider = new GenericProvider([
    'clientId'                => 'test_phpleague_oauth_client',
    'clientSecret'            => 'secret',
    'redirectUri'             => 'https://your-app.com/callback',
    'urlAuthorize'            => 'https://idp.amtgard.com/oauth/authorize',
    'urlAccessToken'          => 'https://idp.amtgard.com/oauth/token',
    'scopes'                  => 'profile email'
]);

// 1. Redirect to Login
if (!isset($_GET['code'])) {
    // Generate PKCE challenge
    $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    
    $_SESSION['oauth2pkceCode'] = $verifier;
    
    $authUrl = $provider->getAuthorizationUrl([
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256'
    ]);
    
    $_SESSION['oauth2state'] = $provider->getState();
    header('Location: ' . $authUrl);
    exit;

// 2. Handle Callback
} elseif (empty($_GET['state']) || ($_GET['state'] !== $_SESSION['oauth2state'])) {
    unset($_SESSION['oauth2state']);
    exit('Invalid State');
} else {
    try {
        $accessToken = $provider->getAccessToken('authorization_code', [
            'code' => $_GET['code'],
            'code_verifier' => $_SESSION['oauth2pkceCode'] ?? null
        ]);
        
        unset($_SESSION['oauth2pkceCode']);

        // Elevate access token to authorization JWT, then fetch profile
        $jwtRequest = $provider->getAuthenticatedRequest(
            'GET',
            'https://idp.amtgard.com/resources/jwt',
            $accessToken
        );
        $jwtResponse = $provider->getParsedResponse($jwtRequest);
        $authJwt = $jwtResponse['jwt'];

        $profileRequest = $provider->getAuthenticatedRequest(
            'GET',
            'https://idp.amtgard.com/resources/userinfo',
            $accessToken,
            ['headers' => ['Authorization' => 'Bearer ' . $authJwt]]
        );
        $userProfile = $provider->getParsedResponse($profileRequest);
        
        print_r($userProfile);
        
    } catch (IdentityProviderException $e) {
        exit('OAuth Error: ' . $e->getMessage());
    }
}
```

---

## 5. Public API Endpoint Overview

This section lists every public-facing endpoint on the IDP, what it is for, and who typically calls it. Documenting these endpoints is intentional — developers need this reference to integrate correctly.

Endpoints fall into five categories: **OAuth server** (standard protocol), **resource API** (your app after login), **client IAM API** (server-to-server policy/metadata for registered apps), **policy service** (backend authorization checks), and **browser UI** (human login and profile management).

### OAuth 2.0 Server

These implement the standard OAuth 2.0 authorization code flow. Every registered client uses them.

| Endpoint | Method | Purpose | Called by |
|----------|--------|---------|-----------|
| `/oauth/authorize` | GET | Start login/consent; returns authorization code to your redirect URI | User's browser (redirect from your app) |
| `/oauth/token` | POST | Exchange authorization code or refresh token for access token | Your server (confidential) or app (public + PKCE) |
| `/oauth/approve` | GET/POST | Consent screen — user approves or denies client access to scopes | User's browser (during first authorization) |

### Resource API (OAuth clients)

Call these after login. Elevate your access token to an authorization JWT first (see [Section 2](#2-api-endpoints--usage)).

| Endpoint | Method | Purpose | Called by |
|----------|--------|---------|-----------|
| `/resources/jwt` | GET | Exchange OAuth access token (or session) for authorization JWT | Your app/server after login |
| `/resources/userinfo` | GET | Full user profile including ORK data and authorization JWT | Your app/server with authorization JWT |
| `/resources/validate` | GET | Lightweight session heartbeat and presence registration | Your app with authorization JWT |

### Client IAM API (confidential server-to-server)

Requires HTTP Basic auth with your OAuth `client_id` and `client_secret`. Your client must be **confidential** and have an **`iam_service`** namespace assigned by IDP admins. See [Section 8](#8-client-iam--jwt-metadata-server-to-server).

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/resources/client/policy-claims` | POST | Add an IAM policy claim for a user (scoped to your `iam_service`) |
| `/resources/client/policy-claims` | DELETE | Remove a policy claim |
| `/resources/client/policy-claims/{idp_user_id}` | GET | List policy claims for a user in your service namespace |
| `/resources/client/user-metadata` | PUT | Set per-login metadata embedded in authorization JWTs |
| `/resources/client/user-metadata/{idp_user_id}` | GET | Read metadata for a login (`?login_id=`) |
| `/resources/client/user-metadata/{idp_user_id}` | DELETE | Clear metadata for a login (`?login_id=`) |

### Policy Service (backend services)

| Endpoint | Method | Purpose | Called by |
|----------|--------|---------|-----------|
| `/api/is_authorized` | POST | Evaluate whether a user's IAM policy satisfies a requirement | Other Amtgard backend services |

This endpoint is public by design — it *is* the authorization check. Services POST a policy and requirement; the IDP returns `{ "is_authorized": true/false }`. It does not require end-user login because callers evaluate policies on behalf of users they have already authenticated.

### Browser UI (end users)

These are HTML pages, not JSON APIs. Users interact with them directly in a browser.

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/` | GET | IDP home page |
| `/auth/login` | GET/POST | Email/password login form |
| `/auth/register` | GET/POST | Create a local IDP account |
| `/auth/logout` | GET | End session |
| `/auth/google`, `/auth/facebook`, `/auth/discord` | GET | Start social login redirect |
| `/auth/google/callback`, etc. | GET | Social provider callback (handled by IDP) |
| `/auth/connect` | GET | ORK→IDP onboarding handoff (see [Section 7](#7-ork-deep-integration-amtgard-specific)) |
| `/resources/profile` | GET | User profile management page (linked accounts, authorized apps, ORK linking) |

### Developer Documentation

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/swagger` | GET | Interactive Swagger UI for testing resource endpoints |
| `/openapi.json` | GET | Machine-readable OpenAPI specification |
| `/docs` | GET | This documentation (Docsify) |

### Typical integration flow

For a third-party app developer, the flow you care about is:

1. **`GET /oauth/authorize`** — redirect user to log in and consent
2. **`POST /oauth/token`** — exchange the returned code for tokens
3. **`GET /resources/jwt`** — elevate the access token to an authorization JWT
4. **`GET /resources/userinfo`** — fetch profile with the authorization JWT
5. **`GET /resources/validate`** — optional heartbeat/presence checks (authorization JWT)

Everything else is either browser UI (login pages), infrastructure (policy service for other backends), client IAM server APIs ([Section 8](#8-client-iam--jwt-metadata-server-to-server)), ORK-specific coupling ([Section 7](#7-ork-deep-integration-amtgard-specific)), or developer tooling (this documentation).

---

## 6. Integration Examples Repository

The [amtgard-idp-client-examples](https://github.com/amtgard/amtgard-idp-client-examples) repository is **not** a client library or SDK. It is a collection of standalone example projects showing how to integrate with the Amtgard IDP using common languages and OAuth libraries.

Each example demonstrates the same core flow documented in this guide:

1. Redirect the user to `/oauth/authorize` with PKCE
2. Handle the callback and exchange the code at `/oauth/token`
3. Call `/resources/jwt` with the access token, then `/resources/userinfo` with the authorization JWT

Examples are available for multiple stacks (PHP, JavaScript/Node.js, etc.). Copy the approach that matches your project rather than installing a shared package — there is no `composer require` wrapper to pull in.

### When to use the examples repo

- You are building a new Amtgard app and want a working starting point
- You want to see PKCE, token exchange, and profile fetch implemented end-to-end
- You prefer reading complete sample code over assembling snippets from this guide

### When to use this guide + standard libraries directly

- You already have OAuth infrastructure (e.g. [PHP League OAuth2 Client](https://oauth2-client.thephpleague.com/) — see Section 4)
- You need only a specific step (token refresh, userinfo call) rather than a full sample app
- Your framework provides its own OAuth module (Passport, NextAuth, etc.)

Browse the examples at: **https://github.com/amtgard/amtgard-idp-client-examples**

---

## 7. ORK Deep Integration (Amtgard-specific)

> [!IMPORTANT]
> **End-note — not a general OAuth integration path.** The flows below are **tight coupling between the Amtgard IDP and [ORK3](https://github.com/amtgard/ork3)** (the Amtgard Online Record Keeper). Third-party app developers using standard OAuth should rely on Section 2 (`/resources/userinfo` and the `ork_profile` field when present). The endpoints in this section are for ORK maintainers and IDP operators coordinating account linking across both systems.

The IDP and ORK share a **bidirectional account link**: each Amtgard player has a **mundane ID** in ORK and a **UUID user ID** in the IDP. Linking lets OAuth clients (including ORK itself) retrieve persona, park, kingdom, dues, and related ORK data via `/resources/userinfo`.

### Shared configuration

Both systems must agree on these secrets and URLs (see `.env.example`):

| Variable | Purpose |
|----------|---------|
| `IDP_ORK_SHARED_SECRET` | HS256 secret for handoff JWTs (`iss=ork,aud=idp`) and completion JWTs (`iss=idp,aud=ork`). Must match ORK byte-for-byte. |
| `ORK_BASE_URL` | Where the IDP redirects after a successful `/auth/connect` handoff (ORK's `idp_link_complete` route). |
| `LINK_ORK_PROFILE_ALLOWED_CLIENT_IDS` | Comma-separated OAuth `client_id` values allowed to call `POST /resources/link-ork-profile` (typically the ORK confidential client only). |

Legacy env name `ORK_LINK_TOKEN_SECRET` is still read as a fallback during the rename to `IDP_ORK_SHARED_SECRET`.

### Flow A — ORK → IDP onboarding handoff (browser)

When ORK prompts a player to create or link an Amtgard login, ORK mints a **short-lived, single-use JWT** (`link_token`) and redirects the user's browser to:

```
GET /auth/connect?link_token=<jwt>&email=<optional>
```

The IDP renders a Login / Register form (email prefilled from the token). On submit:

| Endpoint | Purpose |
|----------|---------|
| `POST /auth/connect/login` | Authenticate an existing IDP user and link to the ORK mundane ID from the JWT |
| `POST /auth/connect/register` | Create a new IDP account and link to the ORK mundane ID |

The link is keyed off the **JWT `sub` claim (mundane ID)**, not the form email — so an IDP account registered with Discord can still link to an ORK profile whose email differs.

After success, the IDP mints a **completion JWT** and redirects to ORK (`ORK_BASE_URL` + `Route=Login/idp_link_complete`) so ORK can write its own `ork_idp_auth` row and clear dashboard banners.

These routes are **HTML browser flows** (CSRF-protected forms). They are intentionally **not** listed in Swagger.

### Flow B — ORK → IDP link mirror (server-to-server)

When ORK completes a link on its side first, it mirrors the result into the IDP:

```
POST /resources/link-ork-profile
Authorization: Basic <ork_confidential_client_id:secret>
Content-Type: application/json

{ "idp_user_id": "<uuid>", "mundane_id": 12345 }
```

| Status | Meaning |
|--------|---------|
| `204` | Link written (or already idempotent) |
| `400` | Missing/invalid body |
| `404` | Unknown `idp_user_id` |
| `409` | `idp_user_id` already linked to a different mundane ID |

Documented in Swagger under the **ORK Integration** tag. Only clients in `LINK_ORK_PROFILE_ALLOWED_CLIENT_IDS` may call this endpoint.

### Flow C — Profile page manual link (browser)

Logged-in users can also link from the IDP profile UI (`GET /resources/profile`):

| Endpoint | Purpose |
|----------|---------|
| `POST /resources/profile/link-ork` | Submit ORK username/password; IDP validates against ORK API and stores profile + token |
| `POST /resources/profile/refresh-ork` | Refresh cached ORK profile data using the stored ORK token |

These are **session-authenticated HTML form POSTs** with CSRF protection. Not in Swagger.

### What general OAuth clients should use

If you are **not** building ORK itself:

1. Use standard OAuth (Sections 1–2).
2. Elevate to an authorization JWT at `GET /resources/jwt`, then call `GET /resources/userinfo` — when the user has linked ORK, the `ork_profile` object is included.
3. Do **not** implement `/auth/connect` or `/resources/link-ork-profile`; those are ORK↔IDP plumbing.

For ORK-side implementation details, coordinate with the ORK maintainers on Discord or the ORK Help & Updates group (Section 1).

---

## 8. Client IAM & JWT Metadata (server-to-server)

> [!IMPORTANT]
> **For registered Amtgard app operators only.** These endpoints let a confidential OAuth client manage IAM policy claims and optional JWT metadata for its users. The IDP is the **authoritative policy store** for ORK IAM — ORK and other services consume policies from the authorization JWT obtained via the two-step flow in [Section 2](#2-api-endpoints--usage).

### Prerequisites

1. **Confidential OAuth client** registered in the IDP (`is_confidential = true`).
2. **`iam_service` assigned** by an IDP admin via `/management/clients`. This must be a **custom** ORK IAM service identifier (e.g. `Skbc`) — not a built-in enum name such as `Documents`, `Idp`, or `Application`. Each identifier is unique across clients.
3. **`iam_service_format`** (optional JSON array) defines the proviso slots for your service namespace, e.g. `["Configuration","Game","Kingdom","Park"]`. When omitted, the IDP uses that default layout.
4. Each client may only create policy rows scoped to its own `client_id` and `iam_service`. At most **25** policy claims per user per client.

### Authentication

All client IAM endpoints use **HTTP Basic Auth**:

```http
Authorization: Basic base64(client_id:client_secret)
```

Use the same `client_id` and `client_secret` as your OAuth confidential client.

### Policy claims

Policy claims use ORK IAM **ORN format**. The IDP stores three columns that concatenate to the full ORN: `service` + `provisos` + `resource`. When calling the client IAM API, you supply `provisos` and `resource`; the `service` is always your client's `iam_service`.

**Add claim** — `POST /resources/client/policy-claims`

```json
{
  "idp_user_id": "550e8400-e29b-41d4-a716-446655440000",
  "provisos": ":0::::",
  "resource": "MyResource/MyAction"
}
```

Response: `204 No Content` (idempotent if the claim already exists).

**Delete claim** — `DELETE /resources/client/policy-claims` (same JSON body).

**List claims** — `GET /resources/client/policy-claims/{idp_user_id}`

```json
{
  "claims": [
    { "service": "Skbc", "provisos": ":0::::", "resource": "MyResource/MyAction" }
  ]
}
```

Only claims for your `iam_service` and `client_id` are returned. Changes invalidate cached authorization JWTs for that user.

### Per-login JWT metadata

Registered clients may attach a small metadata blob (max **300 bytes**) per **login method** (`user_logins.id`) per OAuth client. The IDP embeds this as the `client_metadata` claim in authorization JWTs when `aud` matches your OAuth `client_id` and the active login matches the stored row.

**Set metadata** — `PUT /resources/client/user-metadata`

```json
{
  "idp_user_id": "550e8400-e29b-41d4-a716-446655440000",
  "login_id": 42,
  "metadata": { "role": "editor", "tier": 2 },
  "encoding": "json"
}
```

Rules:
- `login_id` is required and must belong to the user
- `metadata` must be a JSON **object** when `encoding` is `json` (default), or a **base64 string** when `encoding` is `base64`
- Stored payload ≤ 300 bytes; base64 payloads must decode to a JSON object ≤ 300 bytes
- Scoped per login × OAuth client — other clients cannot read or overwrite your metadata

**Get metadata** — `GET /resources/client/user-metadata/{idp_user_id}?login_id=42`

**Delete metadata** — `DELETE /resources/client/user-metadata/{idp_user_id}?login_id=42`

### End-user flow (how metadata reaches your app)

1. Client operator sets policy claims and/or metadata server-to-server (above).
2. User completes standard OAuth (`/oauth/authorize` → `/oauth/token`).
3. Your app calls `GET /resources/jwt` with the access token, then `GET /resources/userinfo` with the authorization JWT.
4. Response includes a `jwt` field; decode it to read `policy` and `client_metadata`.

Example decoded JWT payload (abbreviated):

```json
{
  "sub": "550e8400-e29b-41d4-a716-446655440000",
  "aud": "your-client-id",
  "email": "player@amtgard.com",
  "policy": "[\"Skbc:0::::MyResource/MyAction\"]",
  "client_metadata": { "role": "editor", "tier": 2 },
  "exp": 1717603200
}
```

Use `POST /api/is_authorized` server-side to evaluate `policy` against ORN requirements.

### Swagger

Client IAM endpoints are tagged **Client** in the <a href="/swagger" target="_self">Swagger UI</a>.
