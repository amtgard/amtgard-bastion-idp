# Style refactor — milestone status

**Base branch:** `style-refactor` (tracks `origin/main` at plan commit).

**Stack naming:** `stack/style-refactor-<milestone>` (git-branchless), e.g. `stack/style-refactor-L1` stacked on previous milestone branch.

**Execution order:** L1 → A → C → H → D → E → G → B → F → I → J → K → M → N (see `style-refactor-implementation-plan.md`).

| Milestone | Branch | Status | Commit | Notes |
|-----------|--------|--------|--------|-------|
| L1 | `stack/style-refactor-L1` | done | `15c179c` | Removed symfony/cache (5 pkgs); direct `jedibc/optional` ^1.0; Slim resolves |
| A | `stack/style-refactor-A` | done | `2574926` | strict_types on 53 legacy `src/` files; 11 leaf classes `final`; strict fallout (`ClientRepository` bool, session user_id casts); PHPUnit `dg/bypass-finals` + bootstrap for final mocks; stan 7 errors / `composer cs -- src` exit 2 unchanged vs L1; infection blocked by PHPUnit exit 1 on warnings (pre-existing) |
| C | `stack/style-refactor-C` | done | `b272b2b` | Firebase JWT verify in `Jwt.php`; `emailClaim` + `presentedPvhContext`; call sites deduped; no Lcobucci in `src/`; PHPUnit green; stan 7 errors unchanged vs A; `composer cs -- src` exit 2 unchanged vs A; infection blocked by PHPUnit warnings (pre-existing) |
| H | `stack/style-refactor-H` | pending | | FirebaseJwtTestFactory |
| D | `stack/style-refactor-D` | pending | | PVH auth dedup |
| E | `stack/style-refactor-E` | pending | | PVH Redis projection |
| G | `stack/style-refactor-G` | pending | | Basic auth dedup |
| B | `stack/style-refactor-B` | pending | | enums over booleans |
| F | `stack/style-refactor-F` | pending | | DI + EM bootstrap test |
| I | `stack/style-refactor-I` | pending | | OAuth social template |
| J | `stack/style-refactor-J` | pending | | large class splits |
| K | `stack/style-refactor-K` | pending | | JSON helpers + queue handles |
| M | `stack/style-refactor-M` | pending | | logging pass |
| N | `stack/style-refactor-N` | pending | | OrnClaimRegistry table-driven |

**Orchestrator:** update this table when each milestone completes (`Status`: `done`, `Commit`: short SHA).
