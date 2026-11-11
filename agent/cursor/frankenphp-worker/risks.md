# FrankenPHP worker mode — risks

| Risk | Mitigation |
|------|------------|
| Session lock / `$_SESSION` bleed across requests | `WorkerRequestScope::finish()` every request; test explicitly |
| `RequestLogContext` identity bleed after fatal | Same cleanup in worker `finally` |
| Stale PDO/Redis after long worker life | `max_requests` (500); monitor; no silent reconnect layer in v1 |
| Memory higher than FPM 32M pool | 128M per worker; fewer processes than FPM max children |
| Docsify `/docs` 403 when physical `public/docs/` exists | Caddy rules match nginx: force front controller for `/docs` |
| Dev FrankenPHP without Xdebug | Default dev stays FPM; document trade-off |
| Integ/PCOV on accidental FrankenPHP image | Pin `IDP_DEV_DOCKERFILE=docker/Dockerfile.dev` in integ scripts |
| Composer deploy in slot | Keep Composer in prod image (existing `gb` install path) |
| opcache `validate_timestamps=0` in prod | Rebuild image on deploy (already true for baked code) |
