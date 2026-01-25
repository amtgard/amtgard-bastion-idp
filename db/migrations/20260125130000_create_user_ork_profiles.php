<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateUserOrkProfiles extends AbstractMigration
{
    public function change(): void
    {
        $this->table('user_ork_profiles')
            ->addColumn('user_id', 'integer', ['signed' => false])
            ->addColumn('ork_token', 'string', ['limit' => 255])
            ->addColumn('mundane_id', 'integer')
            ->addColumn('username', 'string', ['limit' => 255])
            ->addColumn('persona', 'string', ['limit' => 255])
            ->addColumn('suspended', 'integer', ['default' => 0])
            ->addColumn('suspended_at', 'datetime', ['null' => true])
            ->addColumn('suspended_until', 'datetime', ['null' => true])
            ->addColumn('email', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('park_id', 'integer', ['null' => true])
            ->addColumn('kingdom_id', 'integer', ['null' => true])
            ->addColumn('dues_through', 'datetime', ['null' => true])
            ->addColumn('heraldry', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('image', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();
    }
}
