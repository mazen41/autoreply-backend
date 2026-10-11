<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class BackupDatabaseTest extends TestCase
{
    public function test_backup_is_encrypted_and_written_to_configured_private_disk(): void
    {
        Storage::fake('backup-test');
        config([
            'filesystems.backup_disk' => 'backup-test',
            'filesystems.backup_path' => 'db-backups',
            'filesystems.backup_retention_days' => 7,
            'database.connections.mysql.database' => 'tenant_db',
        ]);

        DB::shouldReceive('select')->once()->with('SHOW TABLES')->andReturn([
            (object) ['Tables_in_tenant_db' => 'customers'],
        ]);
        DB::shouldReceive('select')->once()->with('SHOW CREATE TABLE `customers`')->andReturn([
            (object) ['Create Table' => 'CREATE TABLE `customers` (`id` int, `email` varchar(255))'],
        ]);
        $query = Mockery::mock();
        $query->shouldReceive('get')->once()->andReturn(collect([
            (object) ['id' => 1, 'email' => 'private@example.test'],
        ]));
        DB::shouldReceive('table')->once()->with('customers')->andReturn($query);

        $this->artisan('backup:database')->assertExitCode(0);

        $files = Storage::disk('backup-test')->files('db-backups');
        self::assertCount(1, $files);
        self::assertStringEndsWith('.sql.enc', $files[0]);
        $encrypted = Storage::disk('backup-test')->get($files[0]);
        self::assertStringNotContainsString('private@example.test', $encrypted);
        self::assertStringContainsString('private@example.test', Crypt::decryptString($encrypted));
    }
}
