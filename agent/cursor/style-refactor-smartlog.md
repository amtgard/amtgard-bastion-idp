# Style refactor — smartlog

Concise execution log for stack milestones (newest first).

## N — `stack/style-refactor-N`

- **N1:** `OrnClaimRegistry::serviceClaimExtensionTable()` — Idp / registered / `BuiltInOrkPolicyServices` → noop; default → `ClientApplicationClaim`.
- **Verify:** PHPUnit 527; stan 3 unchanged vs M; `composer cs -- src tests` exit 2 unchanged vs M; infection green.

## M — `stack/style-refactor-M`

- **M1:** Structured logging — `PvhAuthorizationGate` outcome + `client_id`; `LowLatencyController` validate reject debug + notice context; `Jwt::validateJwtSignature` failure reason at debug (logger isolated from verify catch).
- **M2:** `OAuthSocialCallbackHandler` provider / `provider_user_id` / `email_sha256`; `ClientResourcesController` policy claim mutations; `BaseAuthController` context arrays (no email in log message).
- **M3:** JWT middleware passes logger into `validateJwtRequest`; `ClientResourcesControllerTest` policy client stub `getIdentifier`.
- **Verify:** PHPUnit 527; stan 3 unchanged vs K; `composer cs -- src tests` exit 2 unchanged vs K; infection green.

## K — `stack/style-refactor-K`

- **K1:** `JsonResponseBody::write` / `writeError`; `PvhGate`, `ClientResourcesController`, `LowLatencyController` share encoder + Content-Type.
- **K2:** `QueueHandleTrait` (Builder + Getter + `$handle`); `PubSubQueueHandle` / `PvhQueueHandle` thin DI types; container wiring unchanged.
- **Verify:** PHPUnit 527 (26 errors / 3 failures pre-existing vs J); stan 3 unchanged; cs exit 2 unchanged; infection green.

## J — `stack/style-refactor-J`

- **J1:** `OAuth2ServerController` delegates to `OAuthTokenAction`, `OAuthApproveAction`, `OAuthAuthorizeAction`, `OAuthFlowErrorRenderer`, `OAuthSessionAuthRequestStore` (routes unchanged).
- **J2:** `ResourcesUserinfoService` + `UserOrkProfileEntity::toUserinfoProfileArray()`; ORK link/refresh park resolution moved to `OrkService::resolveParkDataFromPlayer()`.
- **J3:** `ClientIamPolicyService` / `ClientIamMetadataService`; `ClientResourcesController` thinned for policy + metadata mutations.
- **J4:** `UserPolicyClaimReader`, `UserPolicyClaimWriter`, `ClaimOrnValidator`; repository facade + `useClaimsMapper()` test seam.
- **J5:** `PvhQueueMessage` encode/fromJson; `bin/jwt-pvh-worker.php` + `RedisCacheRepository::queueUserValidation` share shape.
- **Verify:** PHPUnit filters for OAuth2, Resources, ClientResources, UserPolicyClaim, RedisCache, PvhQueueMessage; stan 3 pre-existing; cs exit 2; infection green.

## I — `stack/style-refactor-I`

- OAuth social callback dedup (`OAuthSocialCallbackHandler`, session store, repository helpers).
