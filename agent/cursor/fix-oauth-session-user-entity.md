# fix/oauth-session-user-entity

First-time OAuth consent stores the authorization request in the session. `SerializationTrait` omits `UserEntity`, so the restored `OAuthUser` has an uninitialized `userEntity` and `/oauth/authorize` fatals while seeding PVH.

Authorize now reads `OAuthUser::attachedUserEntity()` and, when that property did not survive the session, reloads the IDP user by session id.

- Branch: `fix/oauth-session-user-entity`
- Line coverage: 3357/3631 (92.45%). Mainline was already under the 95% gate; `OAuthUser` is fully covered and the new restore branches are executed.
- Infection on the diff (`--git-diff-lines` against `main`): 7/7 killed, covered MSI 100%.
- Log-tested decision branch: `info` `oauth user reloaded after session restore` in `OAuth2ServerControllerTest::testAuthorizeLogsWhenSessionDropsUserEntity`, separate from the reload behavior test.
