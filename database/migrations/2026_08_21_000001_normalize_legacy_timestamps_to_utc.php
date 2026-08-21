<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Timestamp columns written before the database session timezone matched
     * Laravel's timezone. Database-managed timestamps are intentionally
     * excluded because MySQL already stored those at the correct instant.
     *
     * @var array<string, array<int, string>>
     */
    private const TIMESTAMP_COLUMNS = [
        'users' => ['email_verified_at', 'created_at', 'updated_at'],
        'password_reset_tokens' => ['created_at'],
        'clients' => ['created_at', 'updated_at'],
        'task_templates' => ['created_at', 'updated_at'],
        'schedules' => ['next_run_at', 'created_at', 'updated_at'],
        'runs' => [
            'scheduled_for',
            'queued_at',
            'started_at',
            'finished_at',
            'execution_deadline_at',
            'created_at',
            'updated_at',
        ],
        'audit_logs' => ['created_at'],
        'import_runs' => ['started_at', 'finished_at', 'created_at', 'updated_at'],
        'import_findings' => ['created_at', 'updated_at'],
        'notifications' => ['read_at', 'created_at', 'updated_at'],
    ];

    public function up(): void
    {
        $this->shiftTimestamps($this->legacyServerOffsetSeconds());
    }

    public function down(): void
    {
        $this->shiftTimestamps(-$this->legacyServerOffsetSeconds());
    }

    private function shiftTimestamps(int $seconds): void
    {
        if ($seconds === 0) {
            return;
        }

        foreach (self::TIMESTAMP_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $wrappedColumn = DB::connection()->getQueryGrammar()->wrap($column);

                DB::table($table)
                    ->whereNotNull($column)
                    ->update([
                        $column => DB::raw("DATE_ADD({$wrappedColumn}, INTERVAL {$seconds} SECOND)"),
                    ]);
            }
        }
    }

    private function legacyServerOffsetSeconds(): int
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return 0;
        }

        $defaultConnection = DB::getDefaultConnection();
        $probeConnection = 'legacy_timezone_probe';
        $connection = config("database.connections.{$defaultConnection}");

        unset($connection['timezone']);

        config(["database.connections.{$probeConnection}" => $connection]);
        DB::purge($probeConnection);

        try {
            $result = DB::connection($probeConnection)->selectOne(
                'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS offset_seconds'
            );

            return (int) ($result->offset_seconds ?? 0);
        } finally {
            DB::disconnect($probeConnection);
            config(["database.connections.{$probeConnection}" => null]);
        }
    }
};
