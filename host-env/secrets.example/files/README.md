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

The HTTP profile catalog lists `oauth-private.key`, `oauth-public.key`, and `apple.p8` under `inject_files`. Use a real AuthKey on dev laptops; production hosts without Apple Sign In can use a placeholder file or trim `apple.p8` from a host-specific catalog overlay.

After M3, the app maps inject file basenames to `OAUTH_PRIVATE_KEY`, `OAUTH_PUBLIC_KEY`, and (when present) `APPLE_KEY_FILE_PATH`.
