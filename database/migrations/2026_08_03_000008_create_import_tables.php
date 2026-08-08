<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source_path');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('stats')->nullable();
            $table->boolean('dry_run')->default(false);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('import_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_run_id')->constrained()->cascadeOnDelete();
            $table->string('severity', 16)->default('warning');
            $table->string('category', 64);          // credential_drift, unknown_task, bad_interval, ...
            $table->string('source_file')->nullable();
            $table->unsignedInteger('source_line')->nullable();
            $table->text('message');
            $table->json('context')->nullable();
            $table->boolean('resolved')->default(false);
            $table->timestamps();

            $table->index(['import_run_id', 'severity']);
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_findings');
        Schema::dropIfExists('import_runs');
    }
};
