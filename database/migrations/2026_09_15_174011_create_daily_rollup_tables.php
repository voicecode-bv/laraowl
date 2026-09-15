<?php

use App\Services\RollupWriter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The daily grain of the rollup family.
 *
 * Every table here mirrors its per-minute/per-hour counterpart column for
 * column, which is what lets the dashboard swap one for the other by changing
 * the table a query reads and nothing else. A 30-day view that folded 43,200
 * minute buckets per type reads 30 rows instead.
 */
return new class extends Migration
{
    /**
     * Upper bounds of the latency histogram, mirrored from
     * {@see RollupWriter::LATENCY_BOUNDARIES}.
     */
    private const LATENCY_BOUNDARIES = ['1000', '5000', '10000', '25000', '50000', '100000', '250000', '500000', '1000000', '2500000', '5000000', '10000000', 'inf'];

    public function up(): void
    {
        Schema::create('record_daily_rollups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->timestamp('bucket');
            $this->counterColumns($table);
            $this->latencyColumns($table);

            $table->unique(['project_id', 'type', 'bucket'], 'record_daily_rollups_unique');
            $table->index(['project_id', 'bucket'], 'record_daily_rollups_project_bucket_idx');
        });

        Schema::create('record_group_daily_rollups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->timestamp('bucket');
            $table->string('group_key', 64);
            $table->string('label', 255)->nullable();
            $table->text('sublabel')->nullable();
            $this->counterColumns($table);
            $this->latencyColumns($table);
            $table->timestamp('last_seen_at')->nullable();

            $table->unique(['project_id', 'type', 'bucket', 'group_key'], 'record_group_daily_rollups_unique');
            $table->index(['project_id', 'type', 'bucket'], 'record_group_daily_rollups_lookup_idx');
        });

        Schema::create('record_user_daily_buckets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->timestamp('bucket');
            $table->string('user_key', 64);
            $table->unsignedBigInteger('count')->default(0);
            $table->unsignedBigInteger('error_count')->default(0);
            $table->timestamp('last_seen_at')->nullable();

            $table->unique(['project_id', 'type', 'bucket', 'user_key'], 'record_user_daily_buckets_unique');
            $table->index(['project_id', 'type', 'bucket'], 'record_user_daily_buckets_lookup_idx');
            $table->index(['project_id', 'user_key', 'bucket'], 'record_user_daily_buckets_user_idx');
        });

        Schema::create('record_group_user_daily_buckets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->timestamp('bucket');
            $table->string('group_key', 64);
            $table->string('user_key', 64);

            $table->unique(['project_id', 'type', 'bucket', 'group_key', 'user_key'], 'record_group_user_daily_buckets_unique');
            $table->index(['project_id', 'type', 'group_key'], 'record_group_user_daily_buckets_group_idx');
        });

        Schema::create('record_ip_daily_buckets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->timestamp('bucket');
            $table->string('ip', 45);
            $table->unsignedBigInteger('count')->default(0);

            $table->unique(['project_id', 'type', 'bucket', 'ip'], 'record_ip_daily_buckets_unique');
            $table->index(['project_id', 'type', 'bucket'], 'record_ip_daily_buckets_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_ip_daily_buckets');
        Schema::dropIfExists('record_group_user_daily_buckets');
        Schema::dropIfExists('record_user_daily_buckets');
        Schema::dropIfExists('record_group_daily_rollups');
        Schema::dropIfExists('record_daily_rollups');
    }

    private function counterColumns(Blueprint $table): void
    {
        foreach (['count', 'ok_count', 'client_error_count', 'server_error_count', 'neutral_count', 'hits', 'misses', 'writes', 'deletes', 'authed_count'] as $column) {
            $table->unsignedBigInteger($column)->default(0);
        }

        $table->double('sum_duration')->default(0);
        $table->unsignedBigInteger('count_duration')->default(0);
        $table->double('max_duration')->nullable();
        $table->double('min_duration')->nullable();
    }

    private function latencyColumns(Blueprint $table): void
    {
        foreach (self::LATENCY_BOUNDARIES as $boundary) {
            $table->unsignedBigInteger('lat_le_'.$boundary)->default(0);
        }
    }
};
