<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 96)->unique();            // repost, update_balance_trx, ...
            $table->string('name');
            $table->text('description')->nullable();

            $table->string('executor', 24)->default('http');
            $table->json('config');
            $table->unsignedSmallInteger('timeout_sec')->default(60);
            $table->unsignedSmallInteger('connect_timeout_sec')->default(10);
            $table->boolean('is_active')->default(true);

            // Jejak asal data.
            $table->boolean('legacy_gateway_routed')->default(false); // dirouting di gateway.sh?
            $table->string('legacy_job_file')->nullable();            // jobs/repost.sh
            $table->json('legacy_script_names')->nullable();          // ["repost.sh","recuring.sh"]
            $table->boolean('needs_review')->default(false);
            $table->text('review_notes')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_templates');
    }
};
