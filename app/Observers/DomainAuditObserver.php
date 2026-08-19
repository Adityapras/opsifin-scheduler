<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Schedule;
use Illuminate\Database\Eloquent\Model;

class DomainAuditObserver
{
    private const REDACTED = '[redacted]';

    /** @var array<int, string> */
    private const SENSITIVE = ['auth_secret', 'auth_secret_key', 'password', 'remember_token'];

    public function creating(Model $model): void
    {
        if ($model instanceof Schedule && auth()->check()) {
            $model->created_by ??= auth()->id();
            $model->updated_by = auth()->id();
        }

    }

    public function updating(Model $model): void
    {
        if ($model instanceof Schedule && auth()->check()) {
            $model->updated_by = auth()->id();
        }
    }

    public function created(Model $model): void
    {
        $this->record('created', $model, null, $model->getAttributes());
    }

    public function updated(Model $model): void
    {
        $changes = array_keys($model->getChanges());
        $before = array_intersect_key($model->getOriginal(), array_flip($changes));
        $after = array_intersect_key($model->getAttributes(), array_flip($changes));

        $this->record('updated', $model, $before, $after);
    }

    public function deleted(Model $model): void
    {
        $this->record('deleted', $model, $model->getAttributes(), null);
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed>|null $after */
    private function record(string $action, Model $model, ?array $before, ?array $after): void
    {
        if (! auth()->check()) {
            return;
        }

        AuditLog::record($action, $model, $this->redact($before), $this->redact($after));
    }

    /** @param array<string, mixed>|null $values @return array<string, mixed>|null */
    private function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        foreach (self::SENSITIVE as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = self::REDACTED;
            }
        }

        foreach ($values as $key => $value) {
            if (preg_match('/password|secret|token|authorization|api[_-]?key/i', (string) $key)) {
                $values[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }
}
