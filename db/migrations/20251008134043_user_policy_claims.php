<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class UserPolicyClaims extends AbstractMigration
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
        $this->table('user_policy_claims')
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('updated_by_user_id', 'integer', ['null' => false])
            ->addColumn('updated_at', 'datetime')
            ->addColumn('service', 'string', ['limit' => 50])
            ->addColumn('provisos', 'string', ['limit' => 50])
            ->addColumn('resource', 'string', ['limit' => 50])
            ->addIndex(['user_id', 'service'])
            ->create();
    }
}
