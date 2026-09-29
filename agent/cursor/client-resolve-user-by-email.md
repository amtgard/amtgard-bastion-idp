# client-resolve-user-by-email

A confidential client can resolve an IdP user's public UUID from the account email, then use that id on the existing policy-claim endpoints.

- Branch: `feature/client-resolve-user-by-email` (from `main`)
- Line coverage: 3489/3763 (92.72%). Mainline remains under the 95% gate. New lookup code is fully covered: `ClientUserLookupController` 33/33, `ClientEmailLookupRejection` 13/13, `ClientResourcesRequestResolver` 8/8.
- Infection on those three files: 37/37 killed, covered MSI 100%.
- Log-tested decision branches:
  - `info` `client user lookup by email resolved` in `ClientUserLookupControllerTest::testResolveUserByEmailLogsAResolvedUser`
  - `info` `client user lookup by email rejected` / `missing_email` in `ClientUserLookupControllerTest::testResolveUserByEmailLogsAMissingEmail`
  - `info` `client user lookup by email rejected` / `invalid_email` in `ClientUserLookupControllerTest::testResolveUserByEmailLogsAnInvalidEmail`
  - `info` `client user lookup by email rejected` / `unknown_email` in `ClientUserLookupControllerTest::testResolveUserByEmailLogsAnUnknownEmail`

`GET /resources/client/users/by-email?email=` uses confidential-client Basic auth. `200` returns `{ "idp_user_id", "email" }`. `400` when `email` is missing or not an address. `404` when no account uses that address. The address is not written to the log.

`tests/Config/ContainerResolutionOrderTest` resolves `ClientUserLookupController` from the booted container and checks the named route. Controller resolution still skips when MySQL is unreachable. The route check runs without it.
