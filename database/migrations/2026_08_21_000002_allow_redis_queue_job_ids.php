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
            $table->string('queue_job_id', 64)->nullable()->change();
        });

        // Database queue IDs do not identify Redis payloads. Horizon will
        // republish these queued runs through jobs:reconcile-queued.
        DB::table('runs')
            ->where('status', 'queued')
            ->update(['queue_job_id' => null]);
    }

    public function down(): void
    {
        DB::table('runs')
            ->whereNotNull('queue_job_id')
            ->orderBy('id')
            ->eachById(function (object $run): void {
                if (! ctype_digit((string) $run->queue_job_id)) {
                    DB::table('runs')->where('id', $run->id)->update(['queue_job_id' => null]);
                }
            }, column: 'id', alias: 'id');

        Schema::table('runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('queue_job_id')->nullable()->change();
        });
    }
};
