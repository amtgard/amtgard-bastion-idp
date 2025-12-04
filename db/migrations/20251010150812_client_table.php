<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ClientTable extends AbstractMigration
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
        $this->table('clients')
            ->addColumn('name', 'string')
            ->addColumn('client_id', 'string')
            ->addColumn('client_secret', 'string')
            ->addColumn('redirect_uri', 'string')
            ->addColumn('is_confidential', 'boolean')
            ->addColumn('allowed_scopes', 'json')
            ->addColumn('allowed_grant_types', 'json')
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addIndex(['client_id'], ['unique' => true])
            ->create();
    }
}
