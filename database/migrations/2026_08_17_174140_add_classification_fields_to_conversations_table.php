<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('category')->nullable()->after('status');
            $table->string('priority')->nullable()->after('category');
            $table->float('classification_confidence')->nullable()->after('priority');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['category', 'priority', 'classification_confidence']);
        });
    }
};
