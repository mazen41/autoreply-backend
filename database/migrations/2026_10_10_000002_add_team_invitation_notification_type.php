<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $types = [
        'new_message', 'new_assignment', 'failed_message', 'low_usage', 'overage_alert', 'payment_failed',
        'csat_negative', 'escalation', 'campaign_sent', 'sequence_completed', 'team_invitation',
    ];

    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->enum('type', $this->types)->change();
        });
    }

    public function down(): void
    {
        DB::table('notifications')->where('type', 'team_invitation')->update(['type' => 'new_assignment']);
        Schema::table('notifications', function (Blueprint $table) {
            $table->enum('type', array_slice($this->types, 0, -1))->change();
        });
    }
};
