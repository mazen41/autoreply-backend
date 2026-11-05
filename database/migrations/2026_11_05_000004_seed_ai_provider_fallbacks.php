<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $groq = DB::table('ai_model_prices')->where('provider', 'groq')->where('model_key', 'openai/gpt-oss-120b')->value('id');
        $gemini = DB::table('ai_model_prices')->where('provider', 'gemini')->where('model_key', 'gemini-2.5-flash')->value('id');
        if (!$groq || !$gemini) return;

        DB::table('ai_routing_rules')->whereIn('operating_mode', ['auto', 'economy'])->whereIn('primary_model_id', function ($query) {
            $query->select('id')->from('ai_model_prices')->where('provider', 'groq');
        })->where(function ($query) { $query->whereNull('fallback_model_ids')->orWhere('fallback_model_ids', '[]'); })
            ->update(['fallback_model_ids' => json_encode([$gemini]), 'updated_at' => now()]);
        DB::table('ai_routing_rules')->where('operating_mode', 'premium')->whereIn('primary_model_id', function ($query) {
            $query->select('id')->from('ai_model_prices')->where('provider', 'gemini');
        })->where(function ($query) { $query->whereNull('fallback_model_ids')->orWhere('fallback_model_ids', '[]'); })
            ->update(['fallback_model_ids' => json_encode([$groq]), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Preserve administrator-edited routing preferences on rollback.
    }
};
