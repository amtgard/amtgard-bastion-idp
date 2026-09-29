# fix/missing-iam-namespace

A confidential client with valid credentials and no `iam_service` used to throw `HttpUnauthorizedException`. Slim logged that as `app.ERROR` with a stack trace, and the JSON body was only `401 Unauthorized`.

The IAM middleware now returns **403** `{ "error": "Client is not configured with an IAM service namespace." }` and logs a warning with `client_id`. Invalid credentials still throw 401.

- Branch: `fix/missing-iam-namespace`
- Line coverage: 3448/3722 (92.64%). Mainline remains under the 95% gate.
- Infection on the diff against `main`: 10/10 detected, covered MSI 100% (7 of those were timeouts).
- Log-tested decision branch: `warning` `ConfidentialClientAuth: client has no IAM service namespace` in `ConfidentialClientAuthenticatorTest::testAuthenticateLogsWhenIamServiceIsMissing`.
