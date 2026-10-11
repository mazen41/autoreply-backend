<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('webhooks')
            ->whereNotNull('secret')
            ->orderBy('id')
            ->chunkById(100, function ($webhooks): void {
                foreach ($webhooks as $webhook) {
                    if ($webhook->secret === '') {
                        continue;
                    }

                    try {
                        Crypt::decryptString($webhook->secret);
                        continue;
                    } catch (\Throwable) {
                        // Existing rows contain plaintext signing keys.
                    }

                    DB::table('webhooks')->where('id', $webhook->id)->update([
                        'secret' => Crypt::encryptString($webhook->secret),
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('webhooks')
            ->whereNotNull('secret')
            ->orderBy('id')
            ->chunkById(100, function ($webhooks): void {
                foreach ($webhooks as $webhook) {
                    try {
                        $secret = Crypt::decryptString($webhook->secret);
                    } catch (\Throwable) {
                        continue;
                    }

                    DB::table('webhooks')->where('id', $webhook->id)->update([
                        'secret' => $secret,
                    ]);
                }
            });
    }
};
