<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_task_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_template_id')->constrained()->cascadeOnDelete();

            // Semua nullable: null berarti "pakai nilai template".
            $table->string('method_override', 8)->nullable();
            $table->string('path_override')->nullable();      // /Qa2/apiv_g/api_repost
            $table->text('body_override')->nullable();
            $table->json('headers_override')->nullable();
            $table->unsignedSmallInteger('timeout_override')->nullable();
            $table->unsignedSmallInteger('connect_timeout_override')->nullable();
            $table->string('base_url_override')->nullable();  // client memakai host berbeda utk task ini

            $table->string('legacy_script_file')->nullable(); // gn/repost.sh
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'task_template_id'], 'cto_client_task_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_task_overrides');
    }
};
