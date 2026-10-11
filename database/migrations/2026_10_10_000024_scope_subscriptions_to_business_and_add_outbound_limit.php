<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('subscriptions', 'business_id')) {
            Schema::table('subscriptions', function (Blueprint $table): void {
                $table->foreignId('business_id')->nullable()->constrained('business_profiles')->nullOnDelete();
                $table->index(['business_id', 'status']);
            });
        }

        if (!Schema::hasColumn('payment_intents', 'business_id')) {
            Schema::table('payment_intents', function (Blueprint $table): void {
                $table->foreignId('business_id')->nullable()->constrained('business_profiles')->nullOnDelete();
                $table->index(['business_id', 'status']);
            });
        }

        if (!Schema::hasColumn('packages', 'outbound_messages_limit')) {
            Schema::table('packages', function (Blueprint $table): void {
                // Unlimited preserves current plans until the merchant's plan is explicitly configured.
                $table->integer('outbound_messages_limit')->default(-1);
            });
        }

        $this->backfillBusinessId('subscriptions');
        $this->backfillBusinessId('payment_intents');
    }

    public function down(): void
    {
        if (Schema::hasColumn('subscriptions', 'business_id')) {
            Schema::table('subscriptions', function (Blueprint $table): void {
                $table->dropForeign(['business_id']);
                $table->dropIndex(['business_id', 'status']);
                $table->dropColumn('business_id');
            });
        }

        if (Schema::hasColumn('payment_intents', 'business_id')) {
            Schema::table('payment_intents', function (Blueprint $table): void {
                $table->dropForeign(['business_id']);
                $table->dropIndex(['business_id', 'status']);
                $table->dropColumn('business_id');
            });
        }

        if (Schema::hasColumn('packages', 'outbound_messages_limit')) {
            Schema::table('packages', function (Blueprint $table): void {
                $table->dropColumn('outbound_messages_limit');
            });
        }
    }

    private function backfillBusinessId(string $table): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'business_id')) {
            return;
        }

        $records = DB::table($table . ' as records')
            ->join('users', 'users.id', '=', 'records.user_id')
            ->whereNull('records.business_id')
            ->whereNotNull('users.business_id')
            ->select(['records.id as id', 'users.business_id as owner_business_id']);

        $records->chunkById(200, function ($rows) use ($table): void {
            foreach ($rows as $record) {
                DB::table($table)
                    ->where('id', $record->id)
                    ->whereNull('business_id')
                    ->update(['business_id' => $record->owner_business_id]);
            }
        }, 'records.id', 'id');
    }
};
