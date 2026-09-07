# Compact RS256 heartbeat JWT size

Measured with `Firebase\JWT\JWT::encode` (same path as mint), claims `sub` (UUID), `aud` (`amtgard-ork`), `iss` (`https://idp.amtgard.com`), `exp`, `pvh` (44-char hex), `iat`. Algorithm **RS256** (not HS256).

**627** bytes (`strlen`). Parts: header 36 + payload 247 + signature 342.

Compared to ~1 Ethernet MTU (1500): a cookie-less `GET /resources/validate` with `Host: idp.amtgard.com` + `Authorization: Bearer` is ~85 bytes of framing + 627 token ≈ **712** bytes — fits. Typical extra headers (User-Agent, Accept, Connection; still no session cookie) leave a few hundred bytes of headroom. Remaining tax is the RS256 signature (~342 unpadded / ~344 padded chars, over half the compact token); do not change `alg` to shrink it.
