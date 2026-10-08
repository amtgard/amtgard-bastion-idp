<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateOrkClaimPending extends AbstractMigration
{
    public function change(): void
    {
        $this->table('ork_claim_pending', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'string', ['limit' => 36])
            ->addColumn('idp_user_id', 'string', ['limit' => 36])
            ->addColumn('mundane_id', 'integer', ['signed' => false])
            ->addColumn('username', 'string', ['limit' => 64])
            ->addColumn('expires_at', 'datetime')
            ->addColumn('completed_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime')
            ->addIndex(['idp_user_id'])
            ->addIndex(['expires_at'])
            ->create();
    }
}
