<?php

use Phinx\Migration\AbstractMigration;

class InitialSchema extends AbstractMigration
{
    public function change()
    {
        // Users table
        $this->table('users')
            ->addColumn('email', 'string', ['limit' => 255])
            ->addColumn('password', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('first_name', 'string', ['limit' => 255])
            ->addColumn('last_name', 'string', ['limit' => 255])
            ->addColumn('google_id', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('facebook_id', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('avatar_url', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addIndex(['email'], ['unique' => true])
            ->addIndex(['google_id'], ['unique' => true])
            ->addIndex(['facebook_id'], ['unique' => true])
            ->create();

    }
} 