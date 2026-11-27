# Compact ES256 heartbeat JWT size

Measured with `Firebase\JWT\JWT::encode` (same path as mint), claims `sub` (UUID), `aud` (`amtgard-ork`), `iss` (`https://idp.amtgard.com`), `exp`, `pvh` (44-char hex), `iat`. Algorithm **ES256** (M5; was RS256), with the current key's RFC 7638 `kid` header.

**441** bytes (`strlen`). Parts: header 106 + payload 247 + signature 86.

Compared to ~1 Ethernet MTU (1500): a cookie-less `GET /resources/validate` with `Host: idp.amtgard.com` + `Authorization: Bearer` is ~85 bytes of framing + 441 token ≈ **526** bytes — fits. Typical extra headers (User-Agent, Accept, Connection; still no session cookie) leave ample headroom. The ES256 signature (~86 unpadded chars) is a quarter of the old RS256 signature (~342 unpadded / ~344 padded chars); the added `kid` header (~70 chars) costs less than the signature saves.
