<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BackupDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:database';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backup the database to storage';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting database backup...');

        try {
            $database = config('database.connections.mysql.database');
            $filename = "backup_{$database}_" . date('Y-m-d_H-i-s') . '.sql';
            $path = storage_path('app/backups/' . $filename);

            // Ensure backup directory exists
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }

            // Use Laravel's built-in database backup
            $tables = DB::select('SHOW TABLES');
            $sql = '';

            foreach ($tables as $table) {
                $tableName = array_values((array)$table)[0];
                $this->info("Backing up table: {$tableName}");
                
                $sql .= "-- Backup of table {$tableName}\n";
                $sql .= "-- Date: " . date('Y-m-d H:i:s') . "\n\n";
                
                // Get table structure
                $createTable = DB::select("SHOW CREATE TABLE `{$tableName}`");
                $sql .= $createTable[0]->{'Create Table'} . ";\n\n";
                
                // Get table data
                $rows = DB::table($tableName)->get();
                foreach ($rows as $row) {
                    $columns = array_keys((array)$row);
                    $values = array_map(function($value) {
                        if ($value === null) return 'NULL';
                        if (is_numeric($value)) return $value;
                        return "'" . addslashes($value) . "'";
                    }, (array)$row);
                    
                    $sql .= "INSERT INTO `{$tableName}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $values) . ");\n";
                }
                
                $sql .= "\n-- End of table {$tableName}\n\n";
            }

            file_put_contents($path, $sql);
            $this->info("Database backup completed: {$filename}");
            
            // Clean up old backups (keep last 7 days)
            $this->cleanupOldBackups();
            
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Database backup error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function cleanupOldBackups()
    {
        $backupDir = storage_path('app/backups/');
        $files = glob($backupDir . 'backup_*.sql');
        
        if (empty($files)) {
            return;
        }
        
        // Sort by modification time, oldest first
        usort($files, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });

        // Keep only the last 7 backups
        $filesToDelete = array_slice($files, 0, -7);
        
        foreach ($filesToDelete as $file) {
            if (file_exists($file)) {
                unlink($file);
                $this->info("Deleted old backup: " . basename($file));
            }
        }
    }
}
