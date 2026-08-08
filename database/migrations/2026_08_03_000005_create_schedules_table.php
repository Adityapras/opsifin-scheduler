<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_template_id')->constrained()->cascadeOnDelete();

            $table->string('cron_expression', 128);
            $table->string('timezone', 64)->default('Asia/Jakarta');

            // Flock wajib — di-generate otomatis, tidak bisa lupa (lihat §3.1 poin 4).
            $table->string('lock_key', 191);
            $table->string('lock_mode', 8)->default('skip');
            $table->unsignedSmallInteger('lock_wait_sec')->default(0);

            $table->boolean('is_enabled')->default(false);
            $table->string('catchup_policy', 16)->default('skip');

            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();

            // Jejak asal data dari crontab lama.
            $table->string('legacy_pattern', 24)->default('manual');
            $table->unsignedInteger('legacy_line_no')->nullable();
            $table->text('legacy_command')->nullable();
            $table->boolean('legacy_was_commented')->default(false);
            $table->boolean('legacy_had_flock')->default(false);
            $table->string('legacy_lock_file')->nullable();

            $table->boolean('needs_review')->default(false);
            $table->text('review_notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'task_template_id']);
            $table->index(['is_enabled', 'next_run_at']);
            $table->index('needs_review');
            $table->unique(['client_id', 'task_template_id', 'cron_expression'], 'schedules_matrix_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
