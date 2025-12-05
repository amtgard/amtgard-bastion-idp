<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class UserLoginTable extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function change(): void
    {
        // User Logins table
        $this->table('user_logins')
            ->addColumn('user_id', 'integer')
            ->addColumn('password', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('google_id', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('facebook_id', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('avatar_url', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addIndex(['google_id'], ['unique' => true])
            ->addIndex(['facebook_id'], ['unique' => true])
            ->create();
    }
}
