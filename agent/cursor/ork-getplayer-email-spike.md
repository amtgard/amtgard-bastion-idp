# Spike — ORK3 `Player/GetPlayer` email exposure

**Repo reviewed:** local `ORK3` (`system/lib/ork3/class.Player.php`, `orkservice/Json/index.php`).

## Question

What login/permission posture does the IDP need to read a mundane’s email for Flow A mail?

## Answer

The IDP currently calls **`Player/GetPlayer` with only `MundaneId`** (no ORK `Token`). That is an **anonymous** call.

| Viewer | `Email` in JSON |
|--------|------------------|
| No token / invalid token | Always `""` (redacted), even if `mundane.email` is set in DB |
| Token = **self** (`Token` holder’s mundane id = `MundaneId`) | Full email |
| Token + **park EDIT** on subject’s park (kingdom officers inherit; global ORK admin short-circuits) | Full email |
| Other logged-in user | Email only if subject **not Restricted** and **`ShowEmail === 1`** |

`ShowEmail` is always returned; it reflects the player’s design-modal opt-in, not whether the IDP received the address.

**Implication:** Email Code on the IDP profile **cannot work** against production ORK until the IDP either:

1. Calls `GetPlayer` with the **subject’s ORK token** (user enters ORK password first), or
2. Uses a **confidential integrator** credential ORK agrees to treat like self/officer for this flow, or
3. ORK adds an IDP-specific API that mails the code server-side without exposing email to the IDP.

Username search (`SearchService/Player`) never returns email.

## References

- `Player::GetPlayer()` — `$fetchprivate`, `$_exposeEmail` (~lines 715–795)
- `Authorization::IsAuthorized_h()` — 32-char mundane token
- `Authorization::HasAuthority()` — park/kingdom/admin EDIT paths
