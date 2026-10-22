<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->foreignId('business_id')->nullable()->after('user_id')
                ->constrained('business_profiles')->nullOnDelete();
            $table->index(['business_id', 'user_id', 'is_active'], 'api_keys_business_user_active_idx');
        });

        // Old keys were user-scoped. Constrain them to the user's existing
        // primary business so they cannot continue spanning all workspaces.
        DB::table('api_keys')->orderBy('api_keys.id')->chunkById(200, function ($keys): void {
            foreach ($keys as $key) {
                $businessId = DB::table('users')->where('id', $key->user_id)->value('business_id');
                if ($businessId) {
                    DB::table('api_keys')->where('id', $key->id)->update(['business_id' => $businessId]);
                }
            }
        }, 'api_keys.id', 'id');
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropIndex('api_keys_business_user_active_idx');
            $table->dropConstrainedForeignId('business_id');
        });
    }
};
