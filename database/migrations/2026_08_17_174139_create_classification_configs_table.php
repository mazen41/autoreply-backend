<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classification_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained('business_profiles')->onDelete('cascade');
            $table->boolean('enabled')->default(true);
            $table->json('categories')->nullable();
            $table->json('priorities')->nullable();
            $table->json('intents')->nullable();
            $table->float('confidence_threshold')->default(0.7);
            $table->boolean('auto_routing_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classification_configs');
    }
};
