# fix/missing-iam-namespace

A confidential client with valid credentials and no `iam_service` used to throw `HttpUnauthorizedException`. Slim logged that as `app.ERROR` with a stack trace, and the JSON body was only `401 Unauthorized`.

The IAM middleware now returns **403** `{ "error": "Client is not configured with an IAM service namespace." }` and logs a warning with `client_id`. Invalid credentials still throw 401.

- Branch: `fix/missing-iam-namespace`
- Line coverage: 3466/3741 (92.65%). Mainline remains under the 95% gate.
- Infection on the diff against `main`: 42/45 killed, covered MSI 93%.
- Log-tested decision branches: `warning` `ConfidentialClientAuth: client has no IAM service namespace` in `ConfidentialClientAuthenticatorTest::testAuthenticateLogsWhenIamServiceIsMissing`; `info` `Rejected client IAM fields` in `ManagementControllerTest::testUpdateClientLogsWhenIamFormatIsRejected`.

Saving `iam_service` on an existing client also no-oped. `Client::setIamService()` assigned the private property directly, so the active-record `OnSet` hook never marked `iam_service` dirty and persist wrote nothing. Those accessors now use the generated setters, same as the other client fields. Covered by `ManagementControllerTest::testUpdateClientPersistsIamNamespaceOnExistingClient`.

The IAM format field had the same persist path, so a valid JSON array now saves with the namespace. The admin field also accepts a comma-separated slot list and stores it as that JSON array. An invalid format or namespace redirects back to `/management/clients` with an error banner instead of an uncaught exception.
