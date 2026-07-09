<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Step 1
            $table->string('business_type')->nullable();

            // Step 2
            $table->string('business_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->json('working_days')->nullable();
            $table->string('working_from', 5)->nullable();
            $table->string('working_to', 5)->nullable();

            // Step 3
            $table->text('services')->nullable();
            $table->json('faqs')->nullable();
            $table->string('reply_style')->nullable();

            // Step 4
            $table->string('connected_channel')->nullable();

            $table->timestamps();
        });

        // Add onboarding_completed flag to users
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('onboarding_completed')->default(false)->after('plan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_profiles');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('onboarding_completed');
        });
    }
};
