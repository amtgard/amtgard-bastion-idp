<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class OAuthServerTables extends AbstractMigration
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
        $this->table("access_tokens")
            ->addColumn('token_id',  'string', ['null' => false, 'limit' => 255])
            ->addColumn('expiry_date_time',  'datetime', ['null' => false])
            ->addColumn('user_identifier',  'string', ['null' => false, 'limit' => 255])
            ->addColumn('client_id',  'string', ['null' => false])
            ->create();

        $this->table("auth_codes")
            ->addColumn('token_id',  'string', ['null' => false, 'limit' => 255])
            ->addColumn('expiry_date_time',  'datetime', ['null' => false])
            ->addColumn('user_identifier',  'string', ['null' => false, 'limit' => 255])
            ->addColumn('client_id',  'string', ['null' => false])
            ->addColumn('redirect_uri',  'string', ['null' => false, 'limit' => 255])
            ->create();

        $this->table("clients")
            ->addColumn('client_id',  'string', ['null' => false, 'limit' => 255])
            ->addColumn('client_secret',  'string', ['null' => false, 'limit' => 255])
            ->addColumn('name',  'string', ['null' => false, 'limit' => 255])
            ->addColumn('redirect_uri',  'string', ['null' => false, 'limit' => 255])
            ->addColumn('is_confidential',  'boolean', ['null' => false])
            ->create();

        $this->table("refresh_tokens")
            ->addColumn('token_id',  'string', ['null' => false, 'limit' => 255])
            ->addColumn('access_token_id',  'string', ['null' => false])
            ->addColumn('expiry_date_time',  'datetime', ['null' => false])
            ->create();

        $this->table("scopes")
            ->addColumn('scope_id',  'string', ['null' => false, 'limit' => 255])
            ->create();
    }
}
