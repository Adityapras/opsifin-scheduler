<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('condition', 32);

            // Cakupan bertingkat: kolom yang diisi mempersempit rule. Semuanya
            // null berarti rule berlaku untuk seluruh schedule.
            $table->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('task_template_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained()->cascadeOnDelete();

            // Berapa kegagalan berturut-turut sebelum rule ini berbunyi.
            $table->unsignedSmallInteger('threshold')->default(1);

            // Toleransi keterlambatan sebelum sebuah run dianggap tidak terjadi.
            $table->unsignedSmallInteger('grace_minutes')->default(10);

            // Jeda minimum antar alert dari rule + sasaran yang sama, supaya job
            // yang gagal tiap 6 menit tidak membanjiri notifikasi.
            $table->unsignedSmallInteger('cooldown_minutes')->default(60);

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'condition']);
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_rule_id')->nullable()->constrained()->nullOnDelete();

            // Didenormalisasi seperti tabel runs: alert harus tetap terbaca walau
            // schedule atau rule-nya sudah dihapus.
            $table->foreignId('schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('run_id')->nullable()->constrained()->nullOnDelete();

            $table->string('condition', 32);
            $table->string('status', 16)->default('open');
            $table->string('title');
            $table->text('body')->nullable();

            $table->timestamp('fired_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'fired_at']);
            $table->index(['client_id', 'fired_at']);
            // Dipakai untuk mengecek cooldown: alert terakhir dari rule + schedule.
            $table->index(['alert_rule_id', 'schedule_id', 'fired_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('alert_rules');
    }
};
