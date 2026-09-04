<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateUserJwtGenerations extends AbstractMigration
{
    public function change(): void
    {
        $this->table('user_jwt_generations')
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('user_uuid', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('client_id', 'integer', ['null' => true])
            ->addColumn('aud', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('pvh', 'char', ['limit' => 44, 'null' => false])
            ->addColumn('prev_pvh', 'char', ['limit' => 44, 'null' => true])
            ->addColumn('policy_hash', 'binary', ['limit' => 32, 'null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['user_uuid', 'aud'], ['unique' => true])
            ->addIndex(['pvh'])
            ->addIndex(['user_id'])
            ->create();
    }
}
