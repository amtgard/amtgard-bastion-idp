<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateUserLoginClient extends AbstractMigration
{
    public function change(): void
    {
        $this->table('user_login_client')
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('login_id', 'integer', ['null' => false])
            ->addColumn('client_id', 'integer', ['null' => false])
            ->addColumn('metadata', 'string', ['null' => false, 'limit' => 300])
            ->addColumn('encoding', 'string', ['null' => false, 'limit' => 10, 'default' => 'json'])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['login_id', 'client_id'], ['unique' => true])
            ->addIndex(['user_id', 'client_id'])
            ->create();
    }
}
