<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateUserClientAuthorizations extends AbstractMigration
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
        $this->table("user_client_authorizations")
            ->addColumn('user_identifier', 'string', ['null' => false, 'limit' => 255])
            ->addColumn('client_id', 'integer', ['null' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['user_identifier', 'client_id'], ['unique' => true])
            ->create();
    }
}
