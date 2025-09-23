<?php

use Phinx\Migration\AbstractMigration;

class InitialSchema extends AbstractMigration
{
    public function change()
    {
        // Users table
        $this->table('users')
            ->addColumn('email', 'string', ['limit' => 255])
            ->addColumn('password', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('first_name', 'string', ['limit' => 255])
            ->addColumn('last_name', 'string', ['limit' => 255])
            ->addColumn('google_id', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('facebook_id', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('avatar_url', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addIndex(['email'], ['unique' => true])
            ->addIndex(['google_id'], ['unique' => true])
            ->addIndex(['facebook_id'], ['unique' => true])
            ->create();

        // OAuth Clients table
        $this->table('oauth_clients')
            ->addColumn('name', 'string', ['limit' => 255])
            ->addColumn('client_id', 'string', ['limit' => 255])
            ->addColumn('client_secret', 'string', ['limit' => 255])
            ->addColumn('redirect_uri', 'string', ['limit' => 255])
            ->addColumn('is_confidential', 'boolean')
            ->addColumn('allowed_scopes', 'json')
            ->addColumn('allowed_grant_types', 'json')
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addIndex(['client_id'], ['unique' => true])
            ->create();

        // OAuth Auth Codes table
        $this->table('oauth_auth_codes')
            ->addColumn('identifier', 'string', ['limit' => 100])
            ->addColumn('client_id', 'string', ['limit' => 36])
            ->addColumn('user_id', 'string', ['limit' => 36, 'null' => true])
            ->addColumn('scopes', 'json')
            ->addColumn('revoked', 'boolean')
            ->addColumn('expires_at', 'datetime')
            ->addColumn('created_at', 'datetime')
            ->addColumn('redirect_uri', 'string', ['limit' => 255])
            ->addIndex(['identifier'], ['unique' => true])
            ->create();
    }
} 