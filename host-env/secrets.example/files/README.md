# Inject file placeholders (local dev)

Copy PEM material into the shared vault tree (gitignored on the vault side):

```text
host-env-vault/dev-shared/secrets/files/
  oauth-private.key   # OAuth2 server signing key (required for idp-http)
  oauth-public.key    # OAuth2 server verification key (required for idp-http)
  apple.p8            # Apple Sign In key — dev laptops only; not prod inject unless enabled later
```

Generate OAuth keys for dev (example):

```bash
openssl genrsa -out oauth-private.key 2048
openssl rsa -in oauth-private.key -pubout -out oauth-public.key
```

The HTTP profile catalog lists `oauth-private.key` and `oauth-public.key` under `inject_files`. `apple.p8` is **not** in the catalog; keep it in `secrets/files/` for local Apple login and point `APPLE_KEY_FILE_PATH` in `idp-http.env` at `/run/secrets/files/apple.p8`.

After M3, the app maps inject file basenames to `OAUTH_PRIVATE_KEY`, `OAUTH_PUBLIC_KEY`, and (when present) `APPLE_KEY_FILE_PATH`.
