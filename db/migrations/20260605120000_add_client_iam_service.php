<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddClientIamService extends AbstractMigration
{
    public function change(): void
    {
        $this->table('clients')
            ->addColumn('iam_service', 'string', ['null' => true, 'limit' => 50, 'after' => 'is_dev'])
            ->addIndex(['iam_service'], ['unique' => true])
            ->update();
    }
}
