<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Supports counting an issue's occurrences within an alert rule's time
     * window without scanning the (potentially huge) records table.
     */
    public function up(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->index(['issue_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->dropIndex(['issue_id', 'created_at']);
        });
    }
};
