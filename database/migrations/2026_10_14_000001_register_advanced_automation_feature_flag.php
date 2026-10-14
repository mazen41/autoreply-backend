<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('feature_flags')->updateOrInsert(
            ['key' => 'advanced_automation'],
            [
                'name' => 'Advanced Automation',
                'description' => 'Enables workflow creation, editing, and replay.',
                'is_globally_enabled' => true,
                'requires_business_opt_in' => false,
                'allowed_business_ids' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('feature_flags')->where('key', 'advanced_automation')->delete();
    }
};
