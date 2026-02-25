<?php

use Phinx\Migration\AbstractMigration;

class AddUserRole extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('users');
        $table->addColumn('role', 'enum', [
            'values' => ['user', 'approver', 'admin'],
            'default' => 'user',
            'null' => false,
        ])
        ->update();
    }
}
