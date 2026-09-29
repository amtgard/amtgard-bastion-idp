# unlink-ork-account

A signed-in player can remove a linked ORK player record from the profile page, and ORK can do the same server-to-server.

- Branch: `unlink-ork-account`
- Line coverage: 3442/3716 (92.63%). Mainline remains under the 95% gate. New controller lines are 40/40.
- Infection on the diff against `main`: 7/7 killed, covered MSI 100%.
- Log-tested decision branches:
  - `info` `ork profile unlinked` in `OrkAccountUnlinkControllerTest::testUnlinkFromProfileLogsWhenAProfileIsRemoved`
  - `info` `ork profile unlink skipped` in `OrkAccountUnlinkControllerTest::testUnlinkFromProfileLogsWhenNothingIsLinked` and `testUnlinkFromProfileLogsWhenTheRowDisappearsBeforeDelete`
  - `info` `unlinkOrkProfile unknown idp_user_id` in `OrkAccountUnlinkControllerTest::testUnlinkOrkProfileLogsAnUnknownUser`

Browser: `POST /resources/profile/unlink-ork` (CSRF, session). The Unlink button is on the profile ORK card only when a profile is stored, and the page redirects to `?success=unlinked`.

API: `POST /resources/unlink-ork-profile` with `{ "idp_user_id" }`, same confidential-client allow list as link. `204` when the user exists (including when nothing was linked), `400` for a missing id, `404` for an unknown id.
