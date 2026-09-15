<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->integer('daily_rollup_retention_days')->default(365)->after('rollup_retention_days');
            $table->timestamp('rollups_compacted_through')->nullable()->after('daily_rollup_retention_days');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['daily_rollup_retention_days', 'rollups_compacted_through']);
        });
    }
};
