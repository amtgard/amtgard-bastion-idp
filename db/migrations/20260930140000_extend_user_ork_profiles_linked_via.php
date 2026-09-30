<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ExtendUserOrkProfilesLinkedVia extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "ALTER TABLE user_ork_profiles MODIFY linked_via
             ENUM('self_form', 'ork_handoff', 'mirror', 'claim_ork_code', 'claim_idp')
             NOT NULL DEFAULT 'self_form'",
        );
    }

    public function down(): void
    {
        $this->execute(
            "ALTER TABLE user_ork_profiles MODIFY linked_via
             ENUM('self_form', 'ork_handoff', 'mirror')
             NOT NULL DEFAULT 'self_form'",
        );
    }
}
