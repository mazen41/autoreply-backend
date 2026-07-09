<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            // business_profiles is the actual table name in this project
            $table->unsignedBigInteger('business_id')->nullable();
            $table->foreign('business_id')->references('id')->on('business_profiles')->onDelete('cascade');
            $table->string('type'); // facebook, instagram, gmail, whatsapp, google_reviews
            $table->string('page_id')->nullable();
            $table->string('page_name')->nullable();
            $table->string('instagram_account_id')->nullable();
            $table->text('access_token');             // encrypted
            $table->text('refresh_token')->nullable(); // encrypted
            $table->string('status')->default('connected'); // connected, disconnected, error
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
