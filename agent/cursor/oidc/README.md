# OpenID Connect — IDP provider plan

Make this IDP an OpenID Provider on the existing authorization-code + PKCE server. Ordinary apps can verify who logged in from an `id_token`. ORK profile and IAM stay on the authorization JWT.

**Scope of this pack:** documents only. Implementation starts at [checklist](./checklist.md) M1.

| Doc | Role |
|-----|------|
| [design.md](./design.md) | Why, token layers, claims, endpoints, library choice, done-when |
| [development.md](./development.md) | Locked decisions, nonce and signing hooks, schema, files, tests |
| [checklist.md](./checklist.md) | M0–M10 implementation checklist |

`steverhoades/oauth2-openid-connect-server` v3.0.1 is the token-response plugin. It last shipped on 26 September 2024. Discovery, JWKS, UserInfo, `nonce`, and the `APP_URL` issuer are IDP work. The current JWT library stays; signing is covered by tests.
