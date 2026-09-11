# Style refactor — milestone status

**Base branch:** `style-refactor` (tracks `origin/main` at plan commit).

**Stack naming:** `stack/style-refactor-<milestone>` (git-branchless), e.g. `stack/style-refactor-L1` stacked on previous milestone branch.

**Execution order:** L1 → A → C → H → D → E → G → B → F → I → J → K → M → N (see `style-refactor-implementation-plan.md`).

| Milestone | Branch | Status | Commit | Notes |
|-----------|--------|--------|--------|-------|
| L1 | `stack/style-refactor-L1` | done | `15c179c` | Removed symfony/cache (5 pkgs); direct `jedibc/optional` ^1.0; Slim resolves |
| A | `stack/style-refactor-A` | done | `2574926` | strict_types on 53 legacy `src/` files; 11 leaf classes `final`; strict fallout (`ClientRepository` bool, session user_id casts); PHPUnit `dg/bypass-finals` + bootstrap for final mocks; stan 7 errors / `composer cs -- src` exit 2 unchanged vs L1; infection blocked by PHPUnit exit 1 on warnings (pre-existing) |
| C | `stack/style-refactor-C` | done | `bb1778b` | Firebase JWT verify in `Jwt.php`; `emailClaim` + `presentedPvhContext`; call sites deduped; no Lcobucci in `src/`; PHPUnit green; stan 7 errors unchanged vs A; `composer cs -- src` exit 2 unchanged vs A; infection blocked by PHPUnit warnings (pre-existing) |
| H | `stack/style-refactor-H` | done | `97b0a4a` | `FirebaseJwtTestFactory`; Lcobucci removed from `tests/`; PHPUnit green; stan 7 errors unchanged vs C; `composer cs -- src tests` exit 2 unchanged vs C; infection blocked by PHPUnit warnings (pre-existing) |
| D | `stack/style-refactor-D` | done | `0b60fd0` | `PvhAuthorizationGate` + `OAuthAccessTokenFallback`; middleware/controller dedup; PHPUnit green; stan 7 errors unchanged vs H; `composer cs -- src` exit 2 unchanged vs H; infection blocked by PHPUnit warnings (pre-existing) |
| E | `stack/style-refactor-E` | done | `92389cd` | `PvhCacheRecord::fromGeneration`; JwtPvhRefreshService Optional + debug; PHPUnit green; stan 7 errors unchanged vs D; `composer cs -- src` exit 2 unchanged vs D; infection blocked by PHPUnit warnings (pre-existing) |
| G | `stack/style-refactor-G` | done | `db3b7c0` | `AllowListedConfidentialClientAuthenticator`; middleware + `ConfidentialClientAuthenticator` dedup; PHPUnit green; stan 7 errors unchanged vs E; `composer cs -- src tests` exit 2 unchanged vs E; infection blocked by PHPUnit warnings (pre-existing) |
| B | `stack/style-refactor-B` | done | `bc4bf99` | `ConfidentialClientAuthMode` + `AuthorizationFinalizeRedirect`; middleware/social/auth call sites; PHPUnit green; stan 7 errors unchanged vs G; `composer cs -- src tests` exit 2 unchanged vs G; infection green |
| F | `stack/style-refactor-F` | done | `40e2901` | AmtgardIdpJwt Builder DI; CurrentUserResolver; ContainerResolutionOrderTest; EM ctor hacks removed |
| I | `stack/style-refactor-I` | done | `ff8b2c8` | OAuthSocialCallbackHandler + OAuthSocialRedirectSessionStore; social controllers thin wrappers; createUserFromOAuthProfile + createLoginFromProvider + createUserFromDiscordData; PHPUnit social/repo tests green; stan 3 errors unchanged vs F; cs exit 2 unchanged vs F |
| J | `stack/style-refactor-J` | pending | | large class splits |
| K | `stack/style-refactor-K` | pending | | JSON helpers + queue handles |
| M | `stack/style-refactor-M` | pending | | logging pass |
| N | `stack/style-refactor-N` | pending | | OrnClaimRegistry table-driven |

**Orchestrator:** update this table when each milestone completes (`Status`: `done`, `Commit`: short SHA).
