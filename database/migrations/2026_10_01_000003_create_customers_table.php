<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_profile_id');
            $table->string('name')->nullable();
            $table->string('phone')->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('avatar')->nullable();
            $table->integer('lead_score')->default(0);
            $table->json('tags')->nullable();
            $table->json('custom_fields')->nullable();
            $table->timestamps();

            $table->foreign('business_profile_id')->references('id')->on('business_profiles')->onDelete('cascade');
            $table->index(['business_profile_id', 'created_at'], 'customers_business_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
