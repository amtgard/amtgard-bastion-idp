<?php

use Phinx\Migration\AbstractMigration;

class RemoveUserRole extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('users');
        $table->removeColumn('role')
              ->update();
    }
}
