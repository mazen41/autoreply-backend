<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('business_knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_knowledge_file_id');
            $table->unsignedBigInteger('business_profile_id');
            $table->integer('chunk_index');
            $table->text('content');
            $table->json('embedding'); // Array of floats
            $table->timestamps();

            // Foreign keys
            $table->foreign('business_knowledge_file_id', 'fk_bk_file_id')
                  ->references('id')->on('business_knowledge_files')
                  ->onDelete('cascade');
                  
            $table->foreign('business_profile_id', 'fk_bk_profile_id')
                  ->references('id')->on('business_profiles')
                  ->onDelete('cascade');

            // Index on business_profile_id is critical for tenant isolation
            $table->index('business_profile_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_knowledge_chunks');
    }
};
