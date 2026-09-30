# DEV integ — route matrix

Maps every route registered in `config/routes.php` to integration coverage as of **`stack/dev-integ-c4-route-matrix-doc`** (stack 1–14 + phase C isolation). Update this file when phase D milestones add cases.

**Modes** (see [dev-integ-milestones.md](./dev-integ-milestones.md)):

- **A** — HTTP with curl cookie jar, CSRF on form POSTs, UI flows.
- **B** — No session cookie; Basic, Bearer, or `?key=` only.

**Covered**

| Value | Meaning |
|-------|---------|
| **y** | At least one integ test hits this route with a meaningful assertion. |
| **n** | Not covered yet (phase D backlog). |
| **excluded** | Documented out of scope for integ. |

When a route is legitimately exercised in both modes, **Mode** is `A+B` and **Test class** lists the class(es).

**Summary (C4):** 58 route registrations · **y** 48 · **n** 9 · **excluded** 1 · mailbox (no routes) excluded by policy.

## Matrix

| Method | Path | Route name | Mode | Covered | Test class | Notes |
|--------|------|------------|------|---------|------------|-------|
| GET | `/` | `home` | A | n | — | D1 |
| GET | `/version` | `version` | B | y | `VersionEndpointTest` | |
| GET | `/swagger` | `swagger.documentation` | A | n | — | D1 |
| GET | `/openapi.json` | `swagger.openapi` | B | y | `PublicApiTest` | |
| GET | `/docs` | `swagger.docsify` | A | n | — | D1; optional trailing `/` |
| GET | `/docs/readme.md` | `swagger.docsify_content` | A | n | — | D1 |
| GET | `/docs/README.md` | `swagger.docsify_content_upper` | A | n | — | D1 |
| POST | `/api/is_authorized` | `api.is_authorized` | B | y | `PublicApiTest` | allow + deny |
| GET | `/resources/validate` | `resources.validate` | B | y | `OAuthTokenAndResourcesTest` | Bearer authorization JWT |
| GET | `/resources/userinfo` | `resources.userinfo` | A+B | y | `OAuthTokenAndResourcesTest`, `SocialCallbacksTest`, `AppleCallbackTest` | A: Bearer after social; B: Bearer JWT |
| GET | `/resources/profile` | `resources.profile` | A | y | `UiSessionTest`, `SocialCallbacksTest`, `AppleCallbackTest`, `OrkAndProfileTest`, `ManagementUiTest`, `OAuthApproveTest`, `OAuthTokenAndResourcesTest` | |
| GET | `/resources/clients` | `resources.clients` | A | n | — | D6 player list |
| POST | `/resources/clients/{id}/redirect` | `resources.clients.redirect` | A | y | `ManagementUiTest` | admin operator |
| GET | `/resources/authorizations` | `resources.authorizations` | B | y | `OAuthTokenAndResourcesTest` | access token Bearer |
| POST | `/resources/profile/link-ork` | `resources.profile.link_ork` | A | y | `OrkAndProfileTest` | hits `DevIntegHttpClient` ORK |
| POST | `/resources/profile/refresh-ork` | `resources.profile.refresh_ork` | A | y | `OrkAndProfileTest` | |
| POST | `/resources/profile/unlink-ork` | `resources.profile.unlink_ork` | A | y | `OrkAndProfileTest` | |
| POST | `/resources/profile/revoke` | `resources.profile.revoke` | A | y | `OrkAndProfileTest` | |
| POST | `/resources/link-ork-profile` | `resources.link_ork_profile` | B | y | `ClientIamTest` | no live ORK |
| POST | `/resources/unlink-ork-profile` | `resources.unlink_ork_profile` | B | y | `ClientIamTest` | |
| GET | `/resources/jwt` | `resources.jwt` | A+B | y | `OAuthTokenAndResourcesTest`, `SocialCallbacksTest` | B: access token; A: session only; negative in SocialCallbacksTest |
| POST | `/resources/client/policy-claims` | `resources.client.policy_claims.add` | B | y | `ClientIamTest` | |
| DELETE | `/resources/client/policy-claims` | `resources.client.policy_claims.delete` | B | y | `ClientIamTest` | |
| GET | `/resources/client/policy-claims/{idp_user_id}` | `resources.client.policy_claims.list` | B | y | `ClientIamTest` | |
| GET | `/resources/client/users/by-email` | `resources.client.users.by_email` | B | y | `ClientIamTest` | |
| PUT | `/resources/client/user-metadata` | `resources.client.user_metadata.upsert` | B | y | `ClientIamTest` | |
| GET | `/resources/client/user-metadata/{idp_user_id}` | `resources.client.user_metadata.get` | B | y | `ClientIamTest` | |
| DELETE | `/resources/client/user-metadata/{idp_user_id}` | `resources.client.user_metadata.delete` | B | y | `ClientIamTest` | |
| GET | `/resources/client/service-format` | `resources.client.service_format.get` | B | y | `ClientIamTest` | incl. empty `iam_service` client |
| POST | `/resources/client/service-format` | `resources.client.service_format.create` | B | y | `ClientIamTest` | 409 on second POST |
| PUT | `/resources/client/service-format` | `resources.client.service_format.replace` | B | y | `ClientIamTest` | |
| GET | `/auth/login` | `auth.login` | A | y | `LoginPageTest`, others | session + CSRF |
| POST | `/auth/login` | *(unnamed)* | A | y | `UiSessionTest`, `OAuthApproveTest`, `ManagementUiTest`, `OAuthTokenAndResourcesTest` | |
| GET | `/auth/register` | `auth.register` | A | y | `UiSessionTest` | |
| POST | `/auth/register` | *(unnamed)* | A | y | `UiSessionTest` | |
| GET | `/auth/logout` | `auth.logout` | A | y | `UiSessionTest` | |
| GET | `/auth/google` | `auth.google` | A | y | `SocialCallbacksTest` | vendor redirect only |
| GET | `/auth/google/callback` | `auth.google.callback` | A | y | `SocialCallbacksTest` | incl. `integ-deny` negative |
| GET | `/auth/facebook` | `auth.facebook` | A | y | `SocialCallbacksTest` | |
| GET | `/auth/facebook/callback` | `auth.facebook.callback` | A | y | `SocialCallbacksTest` | |
| GET | `/auth/discord` | `auth.discord` | A | y | `SocialCallbacksTest` | |
| GET | `/auth/discord/callback` | `auth.discord.callback` | A | y | `SocialCallbacksTest` | |
| GET | `/auth/apple` | `auth.apple` | A | y | `AppleCallbackTest` | overlay enables Apple |
| POST | `/auth/apple/callback` | `auth.apple.callback` | A | y | `AppleCallbackTest` | form_post |
| GET | `/auth/connect` | `auth.connect.show` | A | y | `OrkAndProfileTest` | |
| POST | `/auth/connect/login` | `auth.connect.login` | A | y | `OrkAndProfileTest` | jti replay negative |
| POST | `/auth/connect/register` | `auth.connect.register` | A | n | — | D3 |
| GET | `/management/cleantokens` | `management.cleantokens` | B | y | `PublicApiTest` | good + bad key |
| GET | `/management/clients` | `management.clients` | A | y | `ManagementUiTest` | admin 200; player not 200 |
| POST | `/management/clients` | `management.clients.create` | A | y | `ManagementUiTest` | |
| POST | `/management/clients/{id}` | `management.clients.update` | A | n | — | D7 |
| GET | `/management/users/search` | `management.users.search` | A | y | `ManagementUiTest` | |
| POST | `/management/clients/{id}/access` | `management.clients.access.add` | A | y | `ManagementUiTest` | |
| POST | `/management/clients/{id}/access/{userId}/delete` | `management.clients.access.remove` | A | y | `ManagementUiTest` | |
| GET | `/oauth/authorize` | `oauth.authorize` | A | y | `OAuthApproveTest`, `OAuthTokenAndResourcesTest`, `OrkAndProfileTest` | logged-out redirect in OAuthApproveTest |
| POST | `/oauth/authorize` | *(unnamed)* | — | excluded | — | empty 200; not tested ([milestones](./dev-integ-milestones.md)) |
| POST | `/oauth/token` | `oauth.token` | B | y | `OAuthTokenAndResourcesTest` | authorization_code + refresh_token |
| GET | `/oauth/approve` | `oauth.approve` | A | n | — | integ uses POST only |
| POST | `/oauth/approve` | `oauth.approve` | A | y | `OAuthApproveTest`, `OAuthTokenAndResourcesTest`, `OrkAndProfileTest` | allow + deny |

## Out of scope (not in matrix rows)

| Item | Reason |
|------|--------|
| Mailbox / SMTP | No routes or container keys yet ([dev-integ-milestones.md](./dev-integ-milestones.md)) |
| Live vendor / ORK HTTP | Must stay on `DevIntegHttpClient` in `ENVIRONMENT=DEV_INTEG` |

## Phase D backlog (from [dev-integ-coverage-plan.md](./dev-integ-coverage-plan.md))

Routes marked **n** above align with milestones D1–D9 (static docs, auth negatives, connect register, OAuth errors/scopes, resources clients UI, management update, client IAM negatives, validate nuances).
