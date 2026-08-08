<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();          // gn, bravo, aladin, ...
            $table->string('name');                        // GOLDEN NUSA
            $table->string('base_url');                    // https://goldennusa.opsifin.com
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->boolean('is_active')->default(true);

            $table->string('auth_type', 16)->default('basic');
            $table->string('auth_username')->nullable();
            $table->text('auth_secret')->nullable();       // encrypted — password / bearer token
            $table->text('auth_secret_key')->nullable();   // encrypted — API_SECRET_KEY dari *.conf

            // Jejak asal data, dipakai laporan rekonsiliasi & verifikasi manual.
            $table->string('legacy_config_file')->nullable();  // configs/bravo.conf
            $table->string('legacy_script_dir')->nullable();   // gn/
            $table->boolean('needs_review')->default(false);
            $table->text('review_notes')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'code']);
            $table->index('needs_review');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
