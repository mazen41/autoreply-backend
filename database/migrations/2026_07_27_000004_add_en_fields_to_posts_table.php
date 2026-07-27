<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('title_en')->nullable()->after('title');
            $table->text('excerpt_en')->nullable()->after('excerpt');
            $table->longText('body_en')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['title_en', 'excerpt_en', 'body_en']);
        });
    }
};
