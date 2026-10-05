<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salla_cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('country_code', 2);
            $table->unsignedBigInteger('country_id');
            $table->unsignedBigInteger('salla_city_id');
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('name_ar')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['channel_id', 'country_code', 'salla_city_id'], 'salla_cities_channel_country_city_unique');
            $table->index(['channel_id', 'country_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salla_cities');
    }
};
