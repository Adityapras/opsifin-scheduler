<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->string('execution_driver', 16)->default('queue');
            $table->timestamp('prepared_at')->nullable();
            $table->unsignedBigInteger('start_lag_ms')->nullable();
            $table->index(['status', 'prepared_at']);
            // The existing (status, scheduled_for) index is retained.
        });
        DB::table('runs')->whereNotNull('queued_at')->update(['prepared_at' => DB::raw('queued_at')]);
        Schema::create('executor_states', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('owner')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->json('metrics')->nullable();
        });
        DB::table('executor_states')->insert([['name' => 'direct'], ['name' => 'dispatcher']]);
    }

    public function down(): void
    {
        // Rollback never turns direct occurrences into queued work.
        DB::table('runs')->where('status', 'pending')->update([
            'status' => 'skipped', 'finished_at' => now(),
            'error_message' => 'Direct execution withdrawn; occurrence will not be replayed.',
        ]);
        Schema::table('runs', function (Blueprint $table): void {
            $table->dropIndex(['status', 'prepared_at']);
            $table->dropColumn(['execution_driver', 'prepared_at', 'start_lag_ms']);
        });
        Schema::dropIfExists('executor_states');
    }
};
