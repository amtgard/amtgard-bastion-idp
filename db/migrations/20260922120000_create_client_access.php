<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateClientAccess extends AbstractMigration
{
    public function change(): void
    {
        $this->table('client_access')
            ->addColumn('client_id', 'integer', ['null' => false])
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['client_id', 'user_id'], ['unique' => true])
            ->addIndex(['user_id'])
            ->create();
    }
}
