<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasBlockedColumn = Schema::hasColumn('conversations', 'is_blocked');
        $hasDeletedAtColumn = Schema::hasColumn('conversations', 'deleted_at');
        Schema::table('conversations', function (Blueprint $table) use ($hasBlockedColumn, $hasDeletedAtColumn): void {
            if (!$hasBlockedColumn) {
                $table->boolean('is_blocked')->default(false)->after('ai_enabled');
            }
            if (!$hasDeletedAtColumn) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        $hasBlockedColumn = Schema::hasColumn('conversations', 'is_blocked');
        $hasDeletedAtColumn = Schema::hasColumn('conversations', 'deleted_at');
        Schema::table('conversations', function (Blueprint $table) use ($hasBlockedColumn, $hasDeletedAtColumn): void {
            if ($hasBlockedColumn) {
                $table->dropColumn('is_blocked');
            }
            if ($hasDeletedAtColumn) {
                $table->dropSoftDeletes();
            }
        });
    }
};
