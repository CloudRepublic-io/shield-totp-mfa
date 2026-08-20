<?php

declare(strict_types=1);

namespace TotpMfa\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Auto-discovered directly from this package - CodeIgniter's own
 * migration locator scans every registered namespace's
 * Database/Migrations directory (this is exactly how Shield's own
 * migrations get found, with no copying step). Once this package's
 * `TotpMfa` namespace is registered (via Composer, or manually in
 * app/Config/Autoload.php for a drop-in install), `php spark migrate --all`
 * (or `-n TotpMfa`) picks this up with nothing further needed - do
 * NOT also copy this file into app/Database/Migrations/. Doing both
 * creates two migrations that each try to create the same table,
 * which fails with "table already exists" the moment both run
 * together (this package's own test suite, and anyone using
 * `--all`/`-n` without excluding one, would hit exactly that).
 */
class CreateAuthRememberedDevices extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'user_id' => [
                // Must exactly match Shield's own users.id column type
                // for the foreign key below to be valid - MySQL
                // requires identical type/width/signedness between a
                // foreign key and the column it references. Confirmed
                // against Shield's actual migration source
                // (src/Database/Migrations/..._create_auth_tables.php):
                // users.id is INT(11) UNSIGNED, NOT BIGINT.
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'selector' => [
                'type'       => 'VARCHAR',
                'constraint' => 24,
            ],
            'hashed_validator' => [
                'type'       => 'VARCHAR',
                'constraint' => 64, // sha256 hex
            ],
            'device_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
                'null'       => true,
            ],
            'ip_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'user_agent' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'last_used_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'expires_at' => [
                'type' => 'DATETIME',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('selector'); // looked up on every request with the cookie present
        $this->forge->addKey('user_id');  // looked up on the "your devices" page

        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('auth_remembered_devices', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('auth_remembered_devices', true);
    }
}
