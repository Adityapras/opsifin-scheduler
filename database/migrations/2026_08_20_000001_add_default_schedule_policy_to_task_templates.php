<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_templates', function (Blueprint $table): void {
            $table->boolean('auto_assign_to_new_clients')->default(true)->after('is_active');
            $table->string('default_cron_expression', 128)->default('*/5 * * * *')->after('auto_assign_to_new_clients');
            $table->boolean('default_schedule_enabled')->default(false)->after('default_cron_expression');
            $table->boolean('default_prevent_overlap')->default(true)->after('default_schedule_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('task_templates', function (Blueprint $table): void {
            $table->dropColumn([
                'auto_assign_to_new_clients',
                'default_cron_expression',
                'default_schedule_enabled',
                'default_prevent_overlap',
            ]);
        });
    }
};
