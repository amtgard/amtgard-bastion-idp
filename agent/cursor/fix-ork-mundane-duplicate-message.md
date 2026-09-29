# fix/ork-mundane-duplicate-message

Linking an ORK mundane that is already stored hit `ux_user_ork_profiles_mundane_id` and returned Slim's raw `PDOException`. Profile save now raises the same conflict the connect flow uses, and the profile page plus `POST /resources/link-ork-profile` show a friendly message.

- Branch: `fix/ork-mundane-duplicate-message`
- Line coverage: 3373/3647 (92.49%). Mainline remains under the 95% gate.
- Infection on the diff against `main`: 21/21 killed, covered MSI 100%.
- Log-tested decision branch: `warning` `ORK profile link rejected: mundane already linked` in `ResourcesControllerTest::testLinkOrkAccountLogsWhenMundaneIsAlreadyLinked`.
