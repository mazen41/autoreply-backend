<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->unsignedSmallInteger('message_retention_days')->default(365);
            $table->unsignedSmallInteger('media_retention_days')->default(30);
            $table->unsignedSmallInteger('consent_retention_days')->nullable();
        });

        Schema::create('data_rights_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('business_profiles')->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('type', ['export', 'erasure']);
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->string('archive_path')->nullable();
            $table->timestamp('archive_expires_at')->nullable();
            $table->json('receipt')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'status', 'created_at'], 'data_rights_business_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_rights_requests');
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->dropColumn(['message_retention_days', 'media_retention_days', 'consent_retention_days']);
        });
    }
};
