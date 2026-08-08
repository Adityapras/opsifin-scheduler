<?php

namespace App\Models;

use App\Enums\HttpMethod;
use App\Enums\LegacyPattern;
use App\Enums\LockMode;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Schedule extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'lock_mode' => LockMode::class,
            'legacy_pattern' => LegacyPattern::class,
            'is_enabled' => 'boolean',
            'legacy_was_commented' => 'boolean',
            'legacy_had_flock' => 'boolean',
            'needs_review' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
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

    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    public function override(): ?ClientTaskOverride
    {
        return ClientTaskOverride::query()
            ->where('client_id', $this->client_id)
            ->where('task_template_id', $this->task_template_id)
            ->first();
    }

    /**
     * Gabungkan template + override client menjadi satu definisi request final.
     *
     * @return array{method: HttpMethod, url: string, body: ?string, headers: array<string, string>, timeout: int, connect_timeout: int, retries: int}
     */
    public function resolveRequest(): array
    {
        $client = $this->client;
        $template = $this->taskTemplate;
        $override = $this->override();

        $method = $override?->method_override
            ? HttpMethod::from($override->method_override)
            : $template->http_method;

        $baseUrl = rtrim($override?->base_url_override ?: $client->base_url, '/');
        $path = $override?->path_override ?: $template->path_template;

        $headers = $this->substitutePlaceholders(
            array_merge($template->headers ?? [], $override?->headers_override ?? []),
            $client,
        );

        if ($auth = $client->authorizationHeader()) {
            $headers['Authorization'] = $auth;
        }

        return [
            'method' => $method,
            'url' => $baseUrl.'/'.ltrim($path, '/'),
            'body' => $override?->body_override ?? $template->body_template,
            'headers' => $headers,
            'timeout' => $override?->timeout_override ?? $template->default_timeout_sec,
            'connect_timeout' => $override?->connect_timeout_override ?? $template->default_connect_timeout_sec,
            'retries' => $template->default_retries,
        ];
    }

    /**
     * Header seperti `SecretKey` nilainya berbeda tiap client, jadi template
     * menyimpan placeholder dan nilainya baru diisi di sini.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function substitutePlaceholders(array $headers, Client $client): array
    {
        $replacements = [
            '{{client.secret_key}}' => (string) $client->auth_secret_key,
            '{{client.username}}' => (string) $client->auth_username,
            '{{client.code}}' => $client->code,
        ];

        return array_map(
            fn (string $value) => strtr($value, $replacements),
            $headers,
        );
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

    public function recalculateNextRun(): void
    {
        $next = $this->nextRuns(1);
        $this->next_run_at = $next[0] ?? null;
    }

    /**
     * Path lock file absolut untuk schedule ini.
     */
    public function lockFilePath(): string
    {
        return rtrim(config('opsifin_cron.lock_dir'), '/').'/'.$this->lock_key.'.lock';
    }

    /**
     * Lock lapis luar yang dipasang di baris crontab. File-nya sengaja berbeda
     * dari lockFilePath() agar lock runner tidak bentrok dengan induknya sendiri.
     */
    public function cronLockFilePath(): string
    {
        return rtrim(config('opsifin_cron.lock_dir'), '/').'/'.$this->lock_key.'.cron.lock';
    }

    /**
     * Argumen flock sesuai mode lock. Selalu ada — tidak ada schedule tanpa lock.
     */
    public function flockArguments(): string
    {
        return $this->lock_mode === LockMode::Wait
            ? '-w '.max(1, (int) $this->lock_wait_sec)
            : '-n';
    }
}
