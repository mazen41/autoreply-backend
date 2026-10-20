<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('csat_ratings', function (Blueprint $table) {
            $table->string('source', 32)->default('team')->after('feedback');
        });

        Schema::create('csat_survey_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('business_profiles')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('contact_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('opted_out_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'created_at']);
            $table->index(['business_id', 'contact_hash']);
            $table->index(['conversation_id', 'expires_at']);
        });

        Schema::create('csat_survey_suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('business_profiles')->cascadeOnDelete();
            $table->string('contact_hash', 64);
            $table->timestamp('opted_out_at');
            $table->timestamps();
            $table->unique(['business_id', 'contact_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csat_survey_suppressions');
        Schema::dropIfExists('csat_survey_invites');
        Schema::table('csat_ratings', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
