<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateMailboxChallenges extends AbstractMigration
{
    public function change(): void
    {
        $this->table('mailbox_challenges', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'string', ['limit' => 36])
            ->addColumn('purpose', 'string', ['limit' => 32])
            ->addColumn('idp_user_id', 'string', ['limit' => 36, 'null' => true])
            ->addColumn('mundane_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('code_hash', 'string', ['limit' => 64])
            ->addColumn('sent_to_hash', 'string', ['limit' => 64])
            ->addColumn('new_email', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('attempts', 'integer', ['default' => 0])
            ->addColumn('send_count', 'integer', ['default' => 1])
            ->addColumn('stage', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('expires_at', 'datetime')
            ->addColumn('consumed_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime')
            ->addIndex(['sent_to_hash', 'purpose'])
            ->addIndex(['idp_user_id'])
            ->addIndex(['mundane_id'])
            ->addIndex(['expires_at'])
            ->create();
    }
}
