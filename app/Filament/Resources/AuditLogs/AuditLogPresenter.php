<?php

namespace App\Filament\Resources\AuditLogs;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Run;
use App\Models\Schedule;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Mengubah JSON before/after audit entry menjadi baris yang mudah dibaca.
 */
class AuditLogPresenter
{
    /**
     * @return array<int, array{field: string, before: ?string, after: ?string, changed: bool}>
     */
    public static function changes(AuditLog $log): array
    {
        $before = $log->before ?? [];
        $after = $log->after ?? [];
        $rows = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $field) {
            $old = array_key_exists($field, $before) ? self::format($before[$field]) : null;
            $new = array_key_exists($field, $after) ? self::format($after[$field]) : null;

            $rows[] = [
                'field' => (string) $field,
                'before' => $old,
                'after' => $new,
                'changed' => $log->action === 'updated' && $old !== $new,
            ];
        }

        return $rows;
    }

    /** Label record yang diaudit; memakai snapshot bila record sudah dihapus. */
    public static function entityLabel(AuditLog $log): ?string
    {
        $values = ($log->after ?? []) + ($log->before ?? []);

        return match ($log->entity_type) {
            Client::class => $values['code'] ?? Client::query()->whereKey($log->entity_id)->value('code'),
            TaskTemplate::class => $values['key'] ?? TaskTemplate::query()->whereKey($log->entity_id)->value('key'),
            User::class => $values['email'] ?? User::query()->whereKey($log->entity_id)->value('email'),
            Schedule::class => self::scheduleLabel($log, $values),
            Run::class => 'Run #'.$log->entity_id,
            default => null,
        };
    }

    /** @param array<string, mixed> $values */
    private static function scheduleLabel(AuditLog $log, array $values): ?string
    {
        $schedule = Schedule::query()->with(['client', 'taskTemplate'])->find($log->entity_id);
        $client = $schedule?->client?->code ?? Client::query()->whereKey($values['client_id'] ?? null)->value('code');
        $task = $schedule?->taskTemplate?->key ?? TaskTemplate::query()->whereKey($values['task_template_id'] ?? null)->value('key');
        $cron = $schedule?->cron_expression ?? ($values['cron_expression'] ?? null);

        if ($client === null && $task === null) {
            return null;
        }

        return trim(($client ?? '?').' / '.($task ?? '?').($cron ? ' · '.$cron : ''));
    }

    private static function format(mixed $value): ?string
    {
        // Kolom JSON kadang tercatat sebagai string ter-encode; samakan dengan array agar diff sebanding.
        if (is_string($value) && in_array($value[0] ?? '', ['{', '['], true)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : $value;
        }

        // Snapshot `before` memakai serialisasi ISO UTC, `after` memakai waktu lokal; samakan.
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/', $value)) {
            $value = Carbon::parse($value)->timezone(config('app.timezone'))->format('Y-m-d H:i:s');
        }

        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null,
            default => (string) $value,
        };
    }
}
