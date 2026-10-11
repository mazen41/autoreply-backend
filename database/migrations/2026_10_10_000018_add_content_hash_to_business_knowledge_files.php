<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_knowledge_files', function (Blueprint $table) {
            $table->unsignedBigInteger('file_size')->nullable();
            $table->char('content_hash', 64)->nullable();
        });

        DB::table('business_knowledge_files')
            ->select(['id', 'business_profile_id', 'extracted_text'])
            ->orderBy('id')
            ->chunkById(100, function ($files): void {
                foreach ($files as $file) {
                    $text = (string) $file->extracted_text;
                    $normalizedText = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
                    $hash = hash('sha256', $normalizedText);
                    $alreadyAssigned = DB::table('business_knowledge_files')
                        ->where('business_profile_id', $file->business_profile_id)
                        ->where('content_hash', $hash)
                        ->exists();

                    if (!$alreadyAssigned) {
                        DB::table('business_knowledge_files')->where('id', $file->id)->update(['content_hash' => $hash]);
                    }
                }
            });

        Schema::table('business_knowledge_files', function (Blueprint $table) {
            $table->unique(['business_profile_id', 'content_hash'], 'knowledge_files_business_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('business_knowledge_files', function (Blueprint $table) {
            $table->dropUnique('knowledge_files_business_hash_unique');
            $table->dropColumn(['content_hash', 'file_size']);
        });
    }
};
