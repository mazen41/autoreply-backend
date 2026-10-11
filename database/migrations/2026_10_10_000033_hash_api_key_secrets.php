<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->char('secret_hash', 64)->nullable()->unique();
            $table->char('key_last_four', 4)->nullable();
            $table->text('secret_ciphertext')->nullable();
        });

        DB::table('api_keys')->orderBy('id')->chunkById(200, function ($keys): void {
            foreach ($keys as $key) {
                $secret = (string) $key->key;
                DB::table('api_keys')->where('id', $key->id)->update([
                    // Keep the legacy unique column populated with a digest so
                    // older code cannot accidentally expose a stored secret.
                    'key' => hash('sha256', $secret),
                    'secret_hash' => hash('sha256', $secret),
                    'key_last_four' => substr($secret, -4),
                    // Encrypted only to make an explicit migration rollback
                    // possible. Runtime authentication never decrypts this.
                    'secret_ciphertext' => Crypt::encryptString($secret),
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::table('api_keys')->orderBy('id')->chunkById(200, function ($keys): void {
            foreach ($keys as $key) {
                if (!empty($key->secret_ciphertext)) {
                    DB::table('api_keys')->where('id', $key->id)->update([
                        'key' => Crypt::decryptString($key->secret_ciphertext),
                    ]);
                }
            }
        });

        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropUnique(['secret_hash']);
            $table->dropColumn(['secret_hash', 'key_last_four', 'secret_ciphertext']);
        });
    }
};
