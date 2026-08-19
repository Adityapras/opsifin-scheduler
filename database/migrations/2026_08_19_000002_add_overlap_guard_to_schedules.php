<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('schedules', 'prevent_overlap')) {
            Schema::table('schedules', function (Blueprint $table): void {
                $table->boolean('prevent_overlap')->default(true)->after('running_run_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->dropColumn('prevent_overlap');
        });
    }
};
