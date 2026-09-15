<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A day of uptime checks per project.
 *
 * The health check runs every thirty seconds, so a month of one project is
 * roughly 86,400 rows — which is what the availability chart and the summary
 * card used to read, per project, on every page view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uptime_daily_rollups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->timestamp('bucket');
            $table->unsignedBigInteger('checks')->default(0);
            $table->unsignedBigInteger('up_checks')->default(0);
            $table->double('sum_response_time')->default(0);
            $table->unsignedBigInteger('response_count')->default(0);
            $table->integer('max_response_time')->nullable();
            $table->integer('min_response_time')->nullable();
            $table->timestamp('last_checked_at')->nullable();

            $table->unique(['project_id', 'bucket'], 'uptime_daily_rollups_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uptime_daily_rollups');
    }
};
