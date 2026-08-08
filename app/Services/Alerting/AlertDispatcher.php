<?php

namespace App\Services\Alerting;

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Run;
use App\Models\Schedule;
use App\Models\User;
use Filament\Notifications\Notification;

/**
 * Titik keluar tunggal untuk seluruh alert.
 *
 * Saat ini hanya mengendap di aplikasi — baris di tabel `alerts` plus lonceng
 * notifikasi Filament. Channel keluar (Slack, email, Telegram) nanti cukup
 * ditambahkan di deliver(), tanpa menyentuh evaluator.
 */
class AlertDispatcher
{
    /**
     * Catat dan sebarkan satu alert, kecuali rule ini masih dalam cooldown
     * untuk sasaran yang sama.
     */
    public function fire(AlertRule $rule, Schedule $schedule, string $title, ?string $body = null, ?Run $run = null): ?Alert
    {
        if ($this->isInCooldown($rule, $schedule)) {
            return null;
        }

        $alert = Alert::create([
            'alert_rule_id' => $rule->id,
            'schedule_id' => $schedule->id,
            'client_id' => $schedule->client_id,
            'task_template_id' => $schedule->task_template_id,
            'run_id' => $run?->id,
            'condition' => $rule->condition,
            'title' => $title,
            'body' => $body,
            'fired_at' => now(),
        ]);

        $this->deliver($alert);

        return $alert;
    }

    /**
     * Job yang gagal tiap 6 menit akan memicu rule yang sama terus-menerus.
     * Cooldown membuat satu masalah menghasilkan satu notifikasi, bukan ratusan.
     */
    private function isInCooldown(AlertRule $rule, Schedule $schedule): bool
    {
        if ($rule->cooldown_minutes <= 0) {
            return false;
        }

        return Alert::query()
            ->where('alert_rule_id', $rule->id)
            ->where('schedule_id', $schedule->id)
            ->where('fired_at', '>=', now()->subMinutes($rule->cooldown_minutes))
            ->exists();
    }

    private function deliver(Alert $alert): void
    {
        $recipients = User::where('is_active', true)->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::make()
            ->title($alert->title)
            ->body($alert->body)
            ->danger()
            ->sendToDatabase($recipients);
    }
}
