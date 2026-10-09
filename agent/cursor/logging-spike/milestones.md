# Logging spike — milestones

Stack base: `feature/ork-flow-a-mail-via-ork`. See [implementation-plan.md](./implementation-plan.md).

## L1 — spool and SQLite writer

- Branch: `stack/idp-log-l1-spool-sqlite-writer`
- Hourly SQLite WAL under `LOG_ROOT`, JSONL spool, `bin/log-sqlite-writer.php`, query helper `SqliteLogQuery`.
- Gates: `composer test` (SqliteLogBundleTest green).

## L2 — IdpLogging wire

- Branch: `stack/idp-log-l2-idp-logging-wire`
- `IdpLogging`, `LogChannelFactory`, correlation/redaction processors, `CorrelationMiddleware`, container + `.env.example`, optional `docker/compose.log-writer.yml`.
- Gates: `composer test` OK.

## L3 — idp-logs CLI

- Branch: `stack/idp-log-l3-idp-logs-cli`
- `bin/idp-logs.php` (`query`, `bundle`, `drain-once`).

## L3b — logging HTTP integ

- Branch: `stack/idp-log-l3b-logging-integ`
- `LoggingCorrelationIntegTest` (Mode B): `GET /version` echoes valid `X-Request-Id` or returns 16-char hex when absent.
- Fix: `CorrelationMiddleware` sets response header from resolved id after context teardown (L2 bug found by integ).
- Integ overlay: `LOG_SPOOL_ENABLED=true`, `LOG_ROOT=/var/www/idp.amtgard.com/logs/bundles-integ`.
- Spool drain from integ is host-side: after traffic with a known request id, operators run `php bin/idp-logs.php drain-once` (CLI covered in L3 unit tests).
- Gates: `composer test`; `./scripts/integ.sh`.

## L4 — M1 slice (Phase 0 exit)

- Branch: `stack/idp-log-l4-m1-slice`
- `HttpObservabilityMiddleware` (one summary line per request; channel by path/status).
- `/resources/validate` reject paths log at **error**; success paths at **info**.
- `OAuthTokenAction` logs `oauth token rejected` on League `OAuthServerException`.
- Integ: extend `LoggingCorrelationIntegTest` for `/resources/validate` 401 and `/oauth/token` 400 with `X-Request-Id`.
- Gates: `composer test`; `./scripts/integ.sh` (logging integ green; stack may retain unrelated ORK/mailbox integ failures until ORK deploy).
- Phase 0 exit: reconstruct one request from SQLite by `request_id` (operator: traffic → `bin/idp-logs.php drain-once` → `bundle --request-id=`).

## L5 — M2 authn/authz middleware

- Branch: `stack/idp-log-l5-m2-auth-middleware`
- Instrumented M2 checklist: management key gate, local admin/session/OAuth middleware, CSRF, JWT elevation, confidential and allow-listed Basic auth (`IdpLogging` channels: `security`, `oauth`, `audit`, `app`).
- Log-tested branches: `tests/Middleware/M2AuthMiddlewareLoggingTest.php` (dedicated assertions per decision path; behavior tests unchanged).
- Gates: `composer test` (957 tests, line coverage ≥95%); `composer mutate` (covered MSI 85%); `./scripts/integ.sh` (85/87 pass; `LoggingCorrelationIntegTest` green; 2 pre-existing ORK mailbox failures).

## L6 — M3 JWT, PVH gate, Optional throws

- Branch: `stack/idp-log-l6-m3-jwt-pvh`
- M3 checklist: cached/restricted JWT middleware, `OAuthAccessTokenFallback`, `Jwt` debug reasons, `PvhAuthorizationGate` / `PvhGate`, correlation identity on proceed (`RequestLogContext` + `CorrelationProcessor`).
- Log-tested: `M3JwtPvhLoggingTest`, `JwtLoggingTest`, `PvhAuthorizationGateLoggingTest`, `CorrelationProcessorTest`.
- Gates: `composer test` (972 tests); `composer mutate` (covered MSI 86%); `./scripts/integ.sh` (85/87; logging integ green).
- Commit: `2ade89b`

## L7 — M4 workers + PVH queue

- Branch: `stack/idp-log-l7-m4-workers-pvh-queue`
- M4 checklist: `JwtPvhQueueProcessor` + worker bootstrap (`job_id`, `correlation_root=worker`), `PvhQueueMessage` optional `request_id`, `RedisCacheRepository` / `JwtPvhRefreshService` / `PubSubRedisConfig` via `IdpLogging`.
- Log-tested: `M4WorkersPvhQueueLoggingTest`, `RedisCacheRepositoryLoggingTest`, `JwtPvhRefreshServiceLoggingTest`, `PubSubRedisConfigTest::testConnectLogsCriticalWhenAllHostsFail`.
- Gates: `composer test` (984 tests); `composer mutate` (covered MSI 87%); `composer integ` (85/87; `LoggingCorrelationIntegTest` green; 2 ORK mailbox failures).
- Commit: `f77eb79`

## L8 — M5 OAuth authorization server

- Branch: `stack/idp-log-l8-m5-oauth-server`
- M5 checklist: `OAuth2ServerController`, `OAuthAuthorizeAction`, `OAuthApproveAction`, `OAuthFlowErrorRenderer` via `IdpLogging->oauth()` (token path unchanged from L4 except shared error renderer).
- Log-tested: `M5OAuthServerLoggingTest`, existing OAuth controller tests updated for oauth channel assertions.
- Gates: `composer test` (989 tests); `composer mutate` (covered MSI 87%); `./scripts/integ.sh` (85/87; `LoggingCorrelationIntegTest` green; 2 ORK mailbox failures).
- Commit: `c01aec3`

## L9 — M6 client identity (login / social / connect)

- Branch: `stack/idp-log-l9-m6-client-identity`
- M6 checklist: `AuthController`, `RegistrationService`, `BaseAuthController`, OAuth callback validator/handler, Google/Discord/Facebook/Apple auth, `ConnectController`, `OrkLinkTokenService` via `IdpLogging` (`security()` / `oauth()`); emails as `email_sha256`.
- Log-tested: `M6ClientIdentityLoggingTest` (local login, registration service, OAuth validator/handler, Google redirect, connect missing token).
- Gates: `composer test` (995 tests); `composer mutate` (covered MSI 89%); `./scripts/integ.sh` (85/87; logging integ green).
- Commit: `0d182b7`

## L10 — M7 resource APIs, jsonError, 4xx/409

- Branch: `stack/idp-log-l10-m7-resource-apis`
- M7 checklist: `CurrentUserResolver`, `ResourcesController` resource/ORK/profile paths, split client IAM controllers (`ClientPolicyClaimsController`, `ClientUserMetadataController`, `ClientServiceFormatController`, `ClientIamRequestInterpreter`), `ClientIamPolicyService` / `ClientIamMetadataService`, `ResourcesUserinfoService`, `ApiController`, `SwaggerController` via `IdpLogging` (`app()` / `audit()` / `security()`).
- Log-tested: `M7ResourceApisLoggingTest` (JWT 401, linkOrkProfile 400, invalid policy claim, `is_authorized` debug).
- Gates: `composer test` (999 tests); `composer mutate` (covered MSI 89%); `./scripts/integ.sh` (85/87; `LoggingCorrelationIntegTest` green; 2 ORK mailbox failures).
