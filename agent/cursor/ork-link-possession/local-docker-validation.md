# Local Flow A — IDP container ↔ ORK3 container

Prereqs: Docker, IDP repo, ORK3 repo (default path below — use your checkout if different).

| Service | Host URL | Container name (DNS on shared net) |
|---------|----------|-------------------------------------|
| IDP | http://localhost:37080 | `amtgard-idp` |
| ORK3 | http://localhost:19080 | `ork3-php8-app` |

Both stacks join Docker network **`amtgard-idp-shared`** (created by IDP `scripts/dev-up.sh`).

## 1. Start IDP

From **amtgard-idp** repo root:

```bash
./scripts/dev-up.sh
docker compose --project-directory . -p amtgard-idp \
  -f docker/compose.prod.yml -f docker/compose.blue.yml -f docker/compose.dev.yml \
  -f docker/compose.local-ork.yml up -d --build amtgardidpapp
docker exec amtgard-idp bash -lc 'cd /var/www/idp.amtgard.com && vendor/bin/phinx migrate'
```

Add to **`.env`** (not only the compose overlay):

```env
ORK_CLAIM_MAIL_VIA_ORK=1
ORK_JSON_API_BASE_URL=http://ork3-php8-app/orkservice/Json/index.php
ORK_BASE_URL=http://localhost:19080/orkui/
APP_URL=http://localhost:37080

# OAuth confidential client ORK uses for Basic auth (must exist in IDP `clients` table)
LINK_ORK_PROFILE_ALLOWED_CLIENT_IDS=<your_ork_confidential_client_id>
```

Create or reuse a confidential client; its `client_id` must match ORK `IDP_CLIENT_ID` in `config.dev.php` and IDP `LINK_ORK_PROFILE_ALLOWED_CLIENT_IDS`.

## 2. Start ORK3 on the shared network

From **ORK3** repo root (e.g. `…/Development/ORK3`):

```bash
docker network create amtgard-idp-shared 2>/dev/null || true
cp .dev.env.example .dev.env   # once; edit secrets
docker compose -f docker-compose.php8-app.dev.yml -f docker-compose.local-idp.yml up -d --build
```

Apply DB migration:

```bash
docker exec -i ork3-php8-db mariadb -uork -psecret ork \
  < db-migrations/2026-10-05-ork-idp-mailbox-challenge.sql
```

Ensure `IdpIntegration` is registered in `orkservice/Json/index.php` (spike branch).

## 3. ORK3 IDP callback + mail (config + `.dev.env`)

`IdpIntegration` uses **`config.dev.php`**: `IDP_API_URL` (server), `IDP_BASE_URL` (links in mail), `IDP_CLIENT_ID` / `IDP_CLIENT_SECRET`. Default `IDP_API_URL` is `http://host.docker.internal:37080`; on `amtgard-idp-shared` you can set `IDP_API_URL` to `http://amtgard-idp` instead.

Copy **SES** from IDP `.env` into ORK `.dev.env` (Flow A mail is sent by ORK, not the IDP):

```env
AMAZON_SES_HOST=
AMAZON_SES_USERNAME=
AMAZON_SES_PASSWORD=
AMAZON_SES_FROM_EMAIL=
AMAZON_SES_PORT=587
```

`config.dev.php` defines `AMAZON_SES_*` from the container env; `heartbeat.sh` forwards them into php-fpm.

Restart ORK after editing: `docker compose -f docker-compose.php8-app.dev.yml -f docker-compose.local-idp.yml restart ork3app`.

## 4. Smoke checks

From ORK container (nonce must be real — this only checks reachability):

```bash
docker exec ork3-php8-app curl -sf -o /dev/null -w '%{http_code}\n' \
  http://amtgard-idp/version
```

From host (IDP → ORK JSON):

```bash
curl -s 'http://localhost:19080/orkservice/Json/index.php?call=IdpIntegration/BeginClaimOrkMail&request[SendNonce]=test' | head
```

Expect failure/no mail for garbage nonce; logs should show `idp_nonce_rejected`, not connection errors.

## 5. Manual UI path

1. Log in to IDP profile on http://localhost:37080  
2. Email Code for a mundane that exists in local ORK with an email set  
3. In **DEV**, ORK logs the plaintext code in `IdpIntegration BeginClaimOrkMail mailed` (see ORK edit log / container logs) if SES is not configured  
4. Enter code on profile → link should succeed (step 7 in [flow-a-ork-mail-plan.md](./flow-a-ork-mail-plan.md))

## Troubleshooting

| Symptom | Check |
|---------|--------|
| `ork_mailbox_lookup_failed` | IDP cannot reach ORK JSON — `ORK_JSON_API_BASE_URL`, both on `amtgard-idp-shared` |
| ORK never mails / Begin fails | ORK → IDP curl — `IDP_API_URL`, `IDP_CLIENT_*`, `LINK_ORK_PROFILE_ALLOWED_CLIENT_IDS` |
| `ork_code_failed` | Pending claim expired (15m), wrong code, or `ValidateClaimOrkCode` mismatch |
| IDP still calls production ORK | `ORK_JSON_API_BASE_URL` unset in **`.env`** inside container (`docker exec amtgard-idp printenv ORK_JSON_API_BASE_URL`) |
| Success but no email / no `ork_idp_mailbox_challenge` row | SES env missing in ORK `.dev.env`, `no_mailbox` / throttle skip, or mail send failure — check nginx/php logs and mundane email. Stale `ork_idp_auth` no longer blocks mail (reassigned on successful code). |
| Mail path errors / undefined constant | ORK dev must define `AMAZON_SES_*` in `.dev.env` and restart so php-fpm inherits them. |
