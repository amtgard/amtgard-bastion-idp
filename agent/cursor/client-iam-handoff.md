# Client IAM handoff: policy claims, metadata, and email lookup

Handoff for an agent integrating or changing the confidential-client IAM API. The integrator guide is `templates/api.md` section 8. OpenAPI annotations on the controllers are the machine contract. This file is the working summary.

Pull request: https://github.com/amtgard/amtgard-bastion-idp/pull/85

## What this API is

A confidential OAuth client, with an IdP-assigned `iam_service`, can grant and revoke permissions for IdP users and attach a small JSON blob that later appears on that user's authorization JWT. The IdP is the store. The client does not keep a parallel permissions database keyed by IdP accounts.

These calls are server-to-server. They are not tied to whoever is logged into a browser. The caller authenticates as the OAuth client.

## Authentication

```http
Authorization: Basic base64(client_id:client_secret)
```

Same `client_id` and `client_secret` as the confidential OAuth client.

| Status | Body | Meaning |
|--------|------|---------|
| `401` | Slim unauthorized response | Missing or invalid client credentials |
| `403` | `{ "error": "Client is not configured with an IAM service namespace." }` | Credentials are valid. An admin has not assigned `iam_service`. |

Every route below except `GET /resources/client/service-format` uses `ConfidentialClientAuthMiddleware` (`RequireIamService`), so a client with no namespace gets that 403. `GET /resources/client/service-format` uses credential-only auth.

A client can read and write only its own claims and metadata.

## How to name the user

`idp_user_id` is the IdP user UUID (`sub` on the authorization JWT, `id` on `GET /resources/userinfo`).

**The person who just signed into this app.** Elevate the OAuth access token at `GET /resources/jwt` and read `sub`.

**Someone else.** Look them up by the account email stored on the IdP:

`GET /resources/client/users/by-email?email=player@amtgard.com`

```json
{
  "idp_user_id": "550e8400-e29b-41d4-a716-446655440000",
  "email": "player@amtgard.com"
}
```

| Status | Body | Meaning |
|--------|------|---------|
| `400` | `{ "error": "email is required" }` | Query `email` is missing or not an email address |
| `404` | `{ "error": "unknown email" }` | No IdP account uses that address |
| `200` | `{ "idp_user_id", "email" }` | Match. `email` is the stored account email. |

The address is trimmed. It is not written to logs. A hit is `info` `client user lookup by email resolved` with `client_id` and `idp_user_id`. A miss is `info` `client user lookup by email rejected` with `reason` `missing_email`, `invalid_email`, or `unknown_email`.

This lookup does not return `login_id`. It is enough for policy claims. It is not enough to set metadata.

## Policy claims

ORN rows in the client's `iam_service`. The caller sends `provisos` and `resource`. The IdP stores `service` as that client's `iam_service`. At most **25** claims per user per client. `provisos` and `resource` are at most 50 characters. Add is idempotent.

Full ORN shape: `service` + `provisos` + `resource`, for example `Skbc:0::::Officer/Approve`.

`iam_service` is a unique PascalCase name assigned by an IdP admin (`/management/clients`). It must not be a built-in ORK service such as `Documents`, `Idp`, `Application`, or `ORK`.

`provisos` is the colon-separated middle of the ORN. Slot order comes from `iam_service_format`. Empty format uses `["Configuration","Game","Kingdom","Park"]`. `:0::::` sets the first slot to `0` and leaves the rest empty. Resource paths (`Officer/Approve`, `Editor/Write`) are the app's own permission names.

**Add** — `POST /resources/client/policy-claims` → `204`

```json
{
  "idp_user_id": "550e8400-e29b-41d4-a716-446655440000",
  "provisos": ":0::::",
  "resource": "MyResource/MyAction"
}
```

**Delete** — `DELETE /resources/client/policy-claims` with the same body → `204`

**List** — `GET /resources/client/policy-claims/{idp_user_id}`

```json
{
  "claims": [
    { "service": "Skbc", "provisos": ":0::::", "resource": "MyResource/MyAction" }
  ]
}
```

Unknown `idp_user_id` is `404` `{ "error": "unknown idp_user_id" }`. A blank id is `400` `{ "error": "idp_user_id is required" }`. An invalid ORN is `400` with the validator message.

Writes are logged at info: `client iam policy claim added` and `client iam policy claim deleted`, with `client_id` and `idp_user_id`.

On the authorization JWT, `policy` is a JSON string of ORN strings, for example `"[\"Skbc:0::::MyResource/MyAction\"]"`. Evaluate it with `POST /api/is_authorized` (`{ "is_authorized": true/false }`). That check is public. It does not use the client secret.

Proviso layout, if the integrator needs to change slots:

| Method | Path | Result |
|--------|------|--------|
| `GET` | `/resources/client/service-format` | `{ "iam_service", "service_format", "is_default" }` |
| `POST` | `/resources/client/service-format` | Set format when none is stored. Body `{ "service_format": ["Configuration","Kingdom","EventInstance"] }`. `409` if one already exists. |
| `PUT` | `/resources/client/service-format` | Replace the stored format. Same body. |

## Metadata (the small JSON blob)

One JSON object per `(user, login_id, client)`, at most **300 bytes**. It is returned on the authorization JWT as `client_metadata` only when `aud` is this client's `client_id` and the JWT was minted for that same login.

`login_id` is the numeric `user_logins.id` for one login method. It must belong to `idp_user_id`. The authorization JWT and `GET /resources/userinfo` do not include it. There is no list-logins endpoint. For a user who has never signed into this app, email lookup can set policy claims and cannot set metadata until the client already knows a `login_id`.

**Set** — `PUT /resources/client/user-metadata` → `204`

```json
{
  "idp_user_id": "550e8400-e29b-41d4-a716-446655440000",
  "login_id": 42,
  "metadata": { "role": "editor", "tier": 2 },
  "encoding": "json"
}
```

- `encoding` defaults to `json`. Then `metadata` must be a JSON object.
- `encoding: "base64"` means `metadata` is a base64 string that decodes to a JSON object of at most 300 bytes.
- Unknown user or login is `404`. Invalid payload is `400`.

**Get** — `GET /resources/client/user-metadata/{idp_user_id}?login_id=42`

**Delete** — `DELETE /resources/client/user-metadata/{idp_user_id}?login_id=42` → `204`

Other clients cannot read or overwrite this row.

## How the user sees it

1. Backend writes claims and/or metadata with the client secret.
2. User finishes OAuth (`/oauth/authorize` → `/oauth/token`).
3. App calls `GET /resources/jwt` with the access token.
4. Decode the fat `jwt` (not `compact_jwt`) for `policy` and `client_metadata`.

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

`GET /resources/userinfo` is the profile (including `ork_profile` when linked). It does not return `policy` or `client_metadata`. Those stay on the JWT from `/resources/jwt`. `/oauth/userinfo` is the OIDC identity endpoint and does not carry ORK or policy claims.

## Code map

Routes live in `config/routes.php` under the `/resources` group.

| Route | Class |
|-------|--------|
| Policy claim add, delete, list | `src/Controllers/Resource/ClientPolicyClaimsController.php` |
| Metadata put, get, delete | `src/Controllers/Resource/ClientUserMetadataController.php` |
| Email lookup | `src/Controllers/Resource/ClientUserLookupController.php` |
| Service format get, post, put | `src/Controllers/Resource/ClientServiceFormatController.php` |

Shared request mapping (caller, `idp_user_id`, `login_id`, email lookup) is `src/Controllers/Resource/ClientIamRequestInterpreter.php`, which delegates persistence to `src/Utility/Client/ClientResourcesRequestResolver.php`.

| Concern | Class |
|---------|--------|
| Policy rows | `src/Services/ClientIamPolicyService.php` |
| Metadata blob | `src/Services/ClientIamMetadataService.php` |
| Proviso layout | `src/Services/ClientIamServiceFormatService.php` |
| Email reject status and log reason | `src/Utility/Client/ClientEmailLookupRejection.php` |

Tests: `tests/Controllers/ClientResourcesControllerTest.php` (policy, metadata, format) and `tests/Controllers/ClientUserLookupControllerTest.php`. `tests/Config/ContainerResolutionOrderTest.php` resolves these controllers and checks the named routes.
