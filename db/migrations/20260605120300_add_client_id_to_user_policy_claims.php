<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddClientIdToUserPolicyClaims extends AbstractMigration
{
    public function change(): void
    {
        $this->table('user_policy_claims')
            ->addColumn('client_id', 'integer', ['null' => true, 'after' => 'user_id'])
            ->addIndex(['user_id', 'client_id'])
            ->update();
    }
}
