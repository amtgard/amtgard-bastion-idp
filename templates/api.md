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

The Amtgard IdP supports standard OAuth 2.0 endpoints for retrieving user details and validating tokens.

All resource endpoints require an `Authorization` header containing the retrieved Access Token:
```http
Authorization: Bearer <YOUR_ACCESS_TOKEN>
```

### <a href="/swagger#/default/userinfo" target="_self">User Info Endpoint (`GET /resources/userinfo`)</a>
Retrieves the full profile of the authenticated user, including their linked Amtgard ORK profile (Mundane ID, persona, kingdom, park, image, dues status, etc.).
- **Method**: `GET`
- **Response Format**: `application/json`
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

### <a href="/swagger#/default/validate" target="_self">Validate Token & Presence (`GET /resources/validate`)</a>
An optimized, lightweight endpoint to quickly validate a session and register user presence (heartbeat/liveness checks).
- **Method**: `GET`
- **Response Format**: `application/json`
- **Example Response**:
```json
{
  "id": 123,
  "email": "player@amtgard.com",
  "jwt": "..."
}
```

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
Use the acquired `access_token` to retrieve the user's information.

<!-- tabs:start -->

#### **PHP**
```php
$url = 'https://idp.amtgard.com/resources/userinfo';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $accessToken,
    'Accept: application/json'
]);
$response = curl_exec($ch);
$profileData = json_decode($response, true);
curl_close($ch);

echo "Hello, " . $profileData['ork_profile']['persona'];
```

#### **JavaScript**
```javascript
async function fetchUserProfile(accessToken) {
  const response = await fetch('https://idp.amtgard.com/resources/userinfo', {
    method: 'GET',
    headers: {
      'Authorization': `Bearer ${accessToken}`,
      'Accept': 'application/json'
    }
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

async function fetchUserProfile(accessToken: string): Promise<UserProfile> {
  const response = await fetch('https://idp.amtgard.com/resources/userinfo', {
    method: 'GET',
    headers: {
      'Authorization': `Bearer ${accessToken}`,
      'Accept': 'application/json'
    }
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
    'urlResourceOwnerDetails' => 'https://idp.amtgard.com/resources/userinfo',
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

        // Fetch user profile from /resources/userinfo
        $resourceOwner = $provider->getResourceOwner($accessToken);
        $userProfile = $resourceOwner->toArray();
        
        print_r($userProfile);
        
    } catch (IdentityProviderException $e) {
        exit('OAuth Error: ' . $e->getMessage());
    }
}
```

---

## 5. Official Client Library Wrapper (`amtgard-idp-client`)

For developers working inside the official Amtgard ecosystem, you can utilize the `amtgard-idp-client` package to encapsulate both curl queries and endpoint configs.

### Installation
```bash
composer require amtgard/amtgard-idp-client
```

### Basic Usage

```php
<?php
use Amtgard\Idp\Client\IdpClient;

$client = new IdpClient([
    'client_id'     => 'your_client_id',
    'client_secret' => 'your_client_secret',
    'redirect_uri'  => 'https://your-app.com/callback',
    'base_url'      => 'https://idp.amtgard.com'
]);

// Redirect URL helper
$loginUrl = $client->getAuthorizationUrl(['profile', 'email']);

// Retrieve Profile details
if (isset($_GET['code'])) {
    $userProfile = $client->authenticateWithCode($_GET['code']);
    
    echo "Persona: " . $userProfile->getPersona();
    echo "Email: " . $userProfile->getEmail();
    echo "Dues Expiration: " . $userProfile->getDuesThrough();
}
```
