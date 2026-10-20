<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->foreignId('merged_into_customer_id')->nullable()->after('assigned_to')
                ->constrained('customers')->nullOnDelete();
            $table->index(['business_profile_id', 'merged_into_customer_id'], 'customers_business_merge_index');
        });

        Schema::create('customer_merge_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_profile_id')->constrained('business_profiles')->cascadeOnDelete();
            $table->foreignId('source_customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('target_customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('target_before');
            $table->json('target_after');
            $table->json('conversation_ids');
            $table->json('note_ids');
            $table->json('identity_ids');
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
            $table->index(['business_profile_id', 'source_customer_id', 'reversed_at'], 'customer_merge_source_index');
            $table->index(['business_profile_id', 'target_customer_id', 'reversed_at'], 'customer_merge_target_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_merge_records');
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex('customers_business_merge_index');
            $table->dropConstrainedForeignId('merged_into_customer_id');
        });
    }
};
