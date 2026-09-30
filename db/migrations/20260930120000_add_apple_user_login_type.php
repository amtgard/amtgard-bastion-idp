<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddAppleUserLoginType extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "ALTER TABLE user_logins MODIFY type ENUM('google', 'facebook', 'discord', 'local', 'apple') NOT NULL"
        );
    }

    public function down(): void
    {
        $this->execute(
            "ALTER TABLE user_logins MODIFY type ENUM('google', 'facebook', 'discord', 'local') NOT NULL"
        );
    }
}
