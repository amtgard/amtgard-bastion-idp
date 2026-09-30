<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Seed the openid scope so League can grant it, and add a nullable nonce
 * column on auth_codes. Nonce persist/redeem is a later milestone; this
 * only prepares the schema. Idempotent on the scope insert.
 */
final class OpenidScopeAndAuthCodeNonce extends AbstractMigration
{
    public function up(): void
    {
        $this->table('auth_codes')
            ->addColumn('nonce', 'string', ['limit' => 255, 'null' => true])
            ->update();

        $existing = $this->fetchAll('SELECT scope_id FROM scopes');
        $haveIds = array_map(fn($r) => $r['scope_id'], $existing);
        if (!in_array('openid', $haveIds, true)) {
            // Parameterized insert (no raw string interpolation) so this stays
            // injection-safe if the scope list is ever sourced dynamically.
            $this->table('scopes')->insert(['scope_id' => 'openid'])->saveData();
        }
    }

    public function down(): void
    {
        $this->table('auth_codes')
            ->removeColumn('nonce')
            ->update();
        $this->execute("DELETE FROM scopes WHERE scope_id = 'openid'");
    }
}
