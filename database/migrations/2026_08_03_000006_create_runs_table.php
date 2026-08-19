<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->nullable()->constrained()->nullOnDelete();
            // Didenormalisasi supaya filter per client/task tetap cepat walau schedule dihapus.
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_template_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('source_run_id')->nullable()->constrained('runs')->nullOnDelete();
            $table->char('materialization_key', 64)->nullable()->unique();
            $table->timestamp('scheduled_for');
            $table->string('trigger', 16)->default('schedule');
            $table->string('status', 32)->default('queued');
            $table->timestamp('queued_at')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('execution_deadline_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('response_excerpt')->nullable();
            $table->text('error_message')->nullable();
            $table->string('worker', 191)->nullable();

            $table->timestamps();

            $table->index(['schedule_id', 'scheduled_for']);
            $table->index(['status', 'scheduled_for']);
            $table->index(['client_id', 'scheduled_for']);
            $table->index(['task_template_id', 'scheduled_for']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('runs');
    }
};
