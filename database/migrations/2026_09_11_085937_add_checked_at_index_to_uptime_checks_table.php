<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The uptime screen reads a project's checks over a period: filtered by
 * `project_id`, bounded by `checked_at`, ordered by `checked_at` descending.
 * Only the foreign key was indexed, so the period bound and the sort both
 * fell back to scanning and sorting every check the project ever recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uptime_checks', function (Blueprint $table) {
            $table->index(['project_id', 'checked_at'], 'uptime_checks_project_checked_idx');
        });
    }

    public function down(): void
    {
        Schema::table('uptime_checks', function (Blueprint $table) {
            $table->dropIndex('uptime_checks_project_checked_idx');
        });
    }
};
