<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_knowledge_chunks', function (Blueprint $table): void {
            $table->string('embedding_provider', 32)->nullable();
            $table->string('embedding_model', 64)->nullable();
            $table->unsignedSmallInteger('embedding_dim')->nullable();
            $table->index(['business_profile_id', 'embedding_dim'], 'bk_chunks_business_dim_idx');
        });
    }

    public function down(): void
    {
        Schema::table('business_knowledge_chunks', function (Blueprint $table): void {
            $table->dropIndex('bk_chunks_business_dim_idx');
            $table->dropColumn(['embedding_provider', 'embedding_model', 'embedding_dim']);
        });
    }
};
