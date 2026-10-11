<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class BackupDatabase extends Command
{
    protected $signature = 'backup:database';

    protected $description = 'Create an encrypted database backup and remove expired backups';

    public function handle(): int
    {
        $database = (string) config('database.connections.mysql.database', 'database');
        $diskName = (string) config('filesystems.backup_disk', config('filesystems.default', 'local'));
        $directory = trim((string) config('filesystems.backup_path', 'backups'), '/');
        $retentionDays = max(1, (int) config('filesystems.backup_retention_days', 7));
        $disk = Storage::disk($diskName);

        $this->info('Starting encrypted database backup...');

        try {
            $sql = $this->dumpDatabase();
            $filename = sprintf('backup_%s_%s.sql.enc', preg_replace('/[^A-Za-z0-9_-]/', '_', $database), now()->format('Y-m-d_H-i-s'));
            $relativePath = ($directory !== '' ? $directory . '/' : '') . $filename;
            $encrypted = Crypt::encryptString($sql);

            if (!$disk->put($relativePath, $encrypted, ['visibility' => 'private'])) {
                throw new RuntimeException('Configured backup storage did not accept the encrypted backup.');
            }

            $this->restrictLocalPermissions($disk, $relativePath, $directory);
            $this->cleanupExpiredBackups($disk, $directory, $retentionDays);

            $this->info("Encrypted database backup completed: {$relativePath}");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Database backup failed (' . get_class($e) . '). Check backup storage and APP_KEY configuration.');
            report($e);
            return Command::FAILURE;
        }
    }

    private function dumpDatabase(): string
    {
        $sql = '';
        foreach (DB::select('SHOW TABLES') as $table) {
            $tableName = (string) array_values((array) $table)[0];
            if (!preg_match('/^[A-Za-z0-9_]+$/', $tableName)) {
                throw new RuntimeException('Database returned an invalid table name.');
            }

            $this->line("Backing up table: {$tableName}");
            $createTable = DB::select("SHOW CREATE TABLE `{$tableName}`");
            $schema = (string) ($createTable[0]->{'Create Table'} ?? '');
            if ($schema === '') {
                throw new RuntimeException("Database did not return schema for table {$tableName}.");
            }
            $sql .= "-- Backup of table {$tableName}\n{$schema};\n\n";

            foreach (DB::table($tableName)->get() as $row) {
                $record = (array) $row;
                $columns = array_keys($record);
                $values = array_map(static function ($value): string {
                    if ($value === null) {
                        return 'NULL';
                    }
                    if (is_numeric($value)) {
                        return (string) $value;
                    }
                    return "'" . addslashes((string) $value) . "'";
                }, array_values($record));

                $quotedColumns = array_map(static fn ($column) => '`' . str_replace('`', '``', (string) $column) . '`', $columns);
                $sql .= 'INSERT INTO `' . $tableName . '` (' . implode(', ', $quotedColumns) . ') VALUES (' . implode(', ', $values) . ");\n";
            }
            $sql .= "\n";
        }

        return $sql;
    }

    private function restrictLocalPermissions($disk, string $relativePath, string $directory): void
    {
        if (($disk->getConfig()['driver'] ?? null) !== 'local') {
            return;
        }

        $fullPath = $disk->path($relativePath);
        $dirPath = dirname($fullPath);
        if (is_dir($dirPath)) {
            @chmod($dirPath, 0700);
        }
        if (is_file($fullPath)) {
            @chmod($fullPath, 0600);
        }
    }

    private function cleanupExpiredBackups($disk, string $directory, int $retentionDays): void
    {
        $cutoff = now()->subDays($retentionDays)->timestamp;
        foreach ($disk->files($directory) as $path) {
            if (!preg_match('/^backup_.*\.sql\.enc$/', basename($path))) {
                continue;
            }
            if ($disk->lastModified($path) < $cutoff) {
                $disk->delete($path);
                $this->line('Deleted expired encrypted backup: ' . basename($path));
            }
        }
    }
}
