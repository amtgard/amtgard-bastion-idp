<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddClientIamServiceFormat extends AbstractMigration
{
    public function change(): void
    {
        $this->table('clients')
            ->addColumn('iam_service_format', 'text', ['null' => true, 'after' => 'iam_service'])
            ->update();
    }
}
