<?php

namespace App\Models;

use App\Enums\LegacyPattern;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Schedule extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'legacy_pattern' => LegacyPattern::class,
            'is_enabled' => 'boolean',
            'prevent_overlap' => 'boolean',
            'legacy_was_commented' => 'boolean',
            'legacy_had_flock' => 'boolean',
            'needs_review' => 'boolean',
            'next_run_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Schedule $schedule): void {
            if (! $schedule->is_enabled) {
                $schedule->next_run_at = null;

                return;
            }

            if ($schedule->next_run_at === null || $schedule->isDirty(['cron_expression', 'timezone', 'is_enabled'])) {
                $schedule->next_run_at = $schedule->calculateNextRunAt();
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function taskTemplate(): BelongsTo
    {
        return $this->belongsTo(TaskTemplate::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(Run::class)->ofMany('scheduled_for', 'max');
    }

    public function isValidCron(): bool
    {
        return CronExpression::isValidExpression($this->cron_expression);
    }

    /**
     * @return array<int, Carbon>
     */
    public function nextRuns(int $count = 5): array
    {
        if (! $this->isValidCron()) {
            return [];
        }

        $tz = $this->timezone ?: config('app.timezone');
        $cron = new CronExpression($this->cron_expression);

        return array_map(
            fn (\DateTime $d) => Carbon::instance($d)->setTimezone($tz),
            $cron->getMultipleRunDates($count, Carbon::now($tz), false, false, $tz),
        );
    }

    public function previousRun(?Carbon $now = null): ?Carbon
    {
        if (! $this->isValidCron()) {
            return null;
        }

        $tz = $this->timezone ?: config('app.timezone');
        $now ??= Carbon::now($tz);

        return Carbon::instance((new CronExpression($this->cron_expression))
            ->getPreviousRunDate($now->copy()->setTimezone($tz), 0, true, $tz));
    }

    public function calculateNextRunAt(?Carbon $after = null): ?Carbon
    {
        if (! $this->isValidCron()) {
            return null;
        }

        $timezone = $this->timezone ?: config('opsifin_cron.default_timezone');
        $after ??= now();

        return Carbon::instance((new CronExpression($this->cron_expression))
            ->getNextRunDate($after->copy()->setTimezone($timezone), 0, false, $timezone))
            ->setTimezone(config('app.timezone'));
    }

    public function isRunnable(): bool
    {
        $this->loadMissing(['client', 'taskTemplate']);

        return $this->is_enabled
            && (bool) $this->client?->is_active
            && (bool) $this->taskTemplate?->is_active;
    }

    public function pausedReason(): ?string
    {
        $this->loadMissing(['client', 'taskTemplate']);

        return match (true) {
            ! $this->is_enabled => 'schedule',
            ! $this->client?->is_active => 'client',
            ! $this->taskTemplate?->is_active => 'task',
            default => null,
        };
    }

    public static function materializationKey(int $scheduleId, Carbon $scheduledFor): string
    {
        return hash('sha256', 'schedule:'.$scheduleId.':'.$scheduledFor->copy()->utc()->format('Y-m-d\TH:i:00\Z'));
    }
}
