<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('whatsapp_instances', 'business_id')) {
            Schema::table('whatsapp_instances', function (Blueprint $table): void {
                $table->foreignId('business_id')->nullable()->after('user_id')->constrained('business_profiles')->nullOnDelete();
                $table->index(['business_id', 'status']);
            });
        }

        $instances = DB::table('whatsapp_instances as instances')
            ->join('users', 'users.id', '=', 'instances.user_id')
            ->whereNull('instances.business_id')
            ->whereNotNull('users.business_id')
            ->select(['instances.id as id', 'users.business_id as business_id']);

        $instances->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('whatsapp_instances')->where('id', $row->id)
                    ->whereNull('business_id')->update(['business_id' => $row->business_id]);
            }
        }, 'instances.id', 'id');
    }

    public function down(): void
    {
        if (!Schema::hasColumn('whatsapp_instances', 'business_id')) {
            return;
        }

        Schema::table('whatsapp_instances', function (Blueprint $table): void {
            $table->dropForeign(['business_id']);
            $table->dropIndex(['business_id', 'status']);
            $table->dropColumn('business_id');
        });
    }
};
