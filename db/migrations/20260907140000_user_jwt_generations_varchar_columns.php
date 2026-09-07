<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Active Record ORM FieldType::fromTableType() does not accept CHAR.
 * VARCHAR is the supported string type; binary(32) is already supported.
 */
final class UserJwtGenerationsVarcharColumns extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            'ALTER TABLE user_jwt_generations
             MODIFY user_uuid VARCHAR(36) NOT NULL,
             MODIFY pvh VARCHAR(44) NOT NULL,
             MODIFY prev_pvh VARCHAR(44) NULL'
        );
    }

    public function down(): void
    {
        $this->execute(
            'ALTER TABLE user_jwt_generations
             MODIFY user_uuid CHAR(36) NOT NULL,
             MODIFY pvh CHAR(44) NOT NULL,
             MODIFY prev_pvh CHAR(44) NULL'
        );
    }
}
