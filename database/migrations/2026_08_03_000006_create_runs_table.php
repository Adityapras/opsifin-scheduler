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

            $table->string('trigger', 16)->default('cron');
            $table->string('status', 24)->default('running');
            $table->unsignedTinyInteger('attempt')->default(1);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->string('request_method', 8)->nullable();
            $table->text('request_url')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('response_excerpt')->nullable();
            $table->text('error_message')->nullable();
            $table->string('host', 128)->nullable();

            $table->timestamps();

            $table->index(['schedule_id', 'started_at']);
            $table->index(['status', 'started_at']);
            $table->index(['client_id', 'started_at']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runs');
    }
};
