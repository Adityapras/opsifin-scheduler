<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->hasIndex('schedules_client_task_unique')) {
            Schema::table('schedules', function (Blueprint $table): void {
                $table->dropUnique('schedules_client_task_unique');
            });
        }

        if (! $this->hasIndex('schedules_matrix_unique')) {
            Schema::table('schedules', function (Blueprint $table): void {
                $table->unique(
                    ['client_id', 'task_template_id', 'cron_expression'],
                    'schedules_matrix_unique',
                );
            });
        }
    }

    public function down(): void
    {
        // Forward-only: the old pair-only constraint forced duplicate templates.
    }

    private function hasIndex(string $name): bool
    {
        return collect(Schema::getIndexes('schedules'))
            ->contains(fn (array $index): bool => $index['name'] === $name);
    }
};
