<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateOrkClaimSendNonces extends AbstractMigration
{
    public function change(): void
    {
        $this->table('ork_claim_send_nonces', ['id' => false, 'primary_key' => ['nonce_hash']])
            ->addColumn('nonce_hash', 'string', ['limit' => 64])
            ->addColumn('idp_user_id', 'string', ['limit' => 36])
            ->addColumn('mundane_id', 'integer', ['signed' => false])
            ->addColumn('expires_at', 'datetime')
            ->addColumn('consumed_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime')
            ->addIndex(['idp_user_id'])
            ->addIndex(['expires_at'])
            ->create();
    }
}
