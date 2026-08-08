<?php

namespace App\Models;

use App\Enums\AlertCondition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Aturan kapan sebuah alert berbunyi.
 *
 * Cakupannya bertingkat: rule tanpa client/task/schedule berlaku untuk semua,
 * dan mengisi salah satunya mempersempit sasaran. Beberapa rule boleh cocok
 * untuk satu run yang sama — masing-masing menghasilkan alert sendiri.
 */
class AlertRule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'condition' => AlertCondition::class,
            'is_active' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function taskTemplate(): BelongsTo
    {
        return $this->belongsTo(TaskTemplate::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    /**
     * Rule aktif yang cakupannya mencakup schedule ini.
     */
    public function scopeMatching(Builder $query, Schedule $schedule): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('client_id')->orWhere('client_id', $schedule->client_id))
            ->where(fn ($q) => $q->whereNull('task_template_id')->orWhere('task_template_id', $schedule->task_template_id))
            ->where(fn ($q) => $q->whereNull('schedule_id')->orWhere('schedule_id', $schedule->id));
    }

    /**
     * Keterangan cakupan untuk ditampilkan di tabel.
     */
    public function scopeLabel(): string
    {
        if ($this->schedule_id) {
            return $this->schedule?->client?->code.' / '.$this->schedule?->taskTemplate?->key;
        }

        return match (true) {
            (bool) $this->client_id && (bool) $this->task_template_id => $this->client?->code.' / '.$this->taskTemplate?->key,
            (bool) $this->client_id => 'client '.$this->client?->code,
            (bool) $this->task_template_id => 'task '.$this->taskTemplate?->key,
            default => 'all schedules',
        };
    }
}
