<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('schedules', 'next_run_at')) {
            Schema::table('schedules', function (Blueprint $table): void {
                $table->timestamp('next_run_at')->nullable()->after('queue')->index();
            });
        }

        if (! Schema::hasColumn('runs', 'response_excerpt')) {
            Schema::table('runs', function (Blueprint $table): void {
                $table->text('response_excerpt')->nullable()->after('http_status');
            });
        }

        DB::table('runs')->where('status', 'pending')->update(['status' => 'queued']);
        DB::table('runs')->where('status', 'lost')->update([
            'status' => 'failed',
            'error_message' => DB::raw("COALESCE(error_message, 'Legacy lost run migrated to failed.')"),
        ]);
        DB::table('runs')->whereIn('status', [
            'skipped_overlap',
            'skipped_blackout',
            'skipped_disabled',
            'cancelled',
        ])->update(['status' => 'skipped']);

        DB::table('schedules')->where('is_enabled', false)->update(['next_run_at' => null]);
    }

    public function down(): void
    {
        // Forward-only compatibility migration. Dropping lean columns would
        // make the rewritten application unable to boot and could lose output.
    }
};
