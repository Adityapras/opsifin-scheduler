<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Schedules\ScheduleResource;
use App\Filament\Resources\TaskTemplates\TaskTemplateResource;
use App\Models\Client;
use App\Models\Schedule;
use App\Models\TaskTemplate;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Tampilan matrix client × task.
 *
 * 40 client × 27 task = lebih dari seribu kombinasi. Tabel biasa tidak
 * memungkinkan melihat "task apa saja yang aktif untuk client ini" sekaligus
 * "client mana saja yang menjalankan task ini" — matrix inilah yang membuat
 * pengelolaan sebanyak itu masuk akal (§5 Fase 3 rencana).
 *
 * Seluruh penyusunan data ada di sini, bukan di blade: view-nya hanya menerima
 * array baris yang sudah jadi (state sel, tooltip, URL) supaya markup-nya tetap
 * terbaca dan tidak ada query yang tersembunyi di dalam loop.
 */
class ScheduleMatrix extends Page
{
    /** Sel punya schedule dan schedule-nya aktif. */
    public const STATE_ENABLED = 'enabled';

    /** Sel punya schedule tapi sedang dimatikan. */
    public const STATE_DISABLED = 'disabled';

    /** Kombinasi client × task ini belum punya schedule sama sekali. */
    public const STATE_MISSING = 'missing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static ?string $navigationLabel = 'Matrix';

    protected static ?string $title = 'Client × task matrix';

    protected static ?int $navigationSort = 5;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected string $view = 'filament.pages.schedule-matrix';

    public string $clientSearch = '';

    public string $taskSearch = '';

    /**
     * Sembunyikan client dan task yang sama sekali tidak punya schedule aktif —
     * bukan menyembunyikan selnya. Kalau selnya saja yang hilang, schedule
     * nonaktif tampak seolah belum pernah dibuat.
     */
    public bool $onlyEnabled = false;

    private ?Collection $clientCache = null;

    private ?Collection $taskCache = null;

    private ?Collection $scheduleCache = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->is_active ?? false;
    }

    public function canOperate(): bool
    {
        return auth()->user()?->canOperate() ?? false;
    }

    /**
     * Kolom matrix — dipakai view untuk header tabel.
     *
     * @return Collection<int, TaskTemplate>
     */
    public function getTasks(): Collection
    {
        return $this->taskCache ??= TaskTemplate::query()
            ->when($this->taskSearch !== '', fn ($q) => $q->where(function ($q) {
                $q->where('key', 'like', '%'.$this->taskSearch.'%')
                    ->orWhere('name', 'like', '%'.$this->taskSearch.'%');
            }))
            ->when($this->onlyEnabled, fn ($q) => $q->whereHas(
                'schedules',
                fn ($q) => $q->where('is_enabled', true)
            ))
            ->orderBy('key')
            ->get();
    }

    /**
     * Header kolom yang sudah jadi. Key task terlalu panjang untuk lebar kolom
     * matrix, jadi yang ditampilkan dipotong satu baris — teks lengkapnya
     * dibaca lewat readout di atas tabel dan atribut title.
     *
     * @return array<int, array{id: int, key: string, name: string, readout: string, tooltip: string, url: string}>
     */
    public function getTaskHeaders(): array
    {
        return $this->getTasks()
            ->map(fn (TaskTemplate $task) => [
                'id' => $task->id,
                'key' => $task->key,
                'name' => $task->name,
                'readout' => $task->key.'  ·  '.$task->name.'  ·  '
                    .$task->http_method->value.' '.$task->path_template,
                'tooltip' => $task->key."\n".$task->name."\n"
                    .$task->http_method->value.' '.$task->path_template,
                'url' => TaskTemplateResource::getUrl('edit', ['record' => $task->getKey()]),
            ])
            ->all();
    }

    /**
     * Baris matrix, lengkap dengan sel yang sudah dihitung.
     *
     * @return array<int, array{client: Client, enabled_count: int, cells: array<int, array{task_id: int, state: string, readout: string, tooltip: string, url: string}>}>
     */
    public function getRows(): array
    {
        $tasks = $this->getTasks();
        $schedules = $this->getScheduleMap();

        return $this->getClients()
            ->map(function (Client $client) use ($tasks, $schedules) {
                $cells = $tasks
                    ->map(fn (TaskTemplate $task) => $this->buildCell(
                        $client,
                        $task,
                        $schedules->get($client->id.':'.$task->id),
                    ))
                    ->all();

                return [
                    'client' => $client,
                    'enabled_count' => collect($cells)->where('state', self::STATE_ENABLED)->count(),
                    'cells' => $cells,
                ];
            })
            ->all();
    }

    /**
     * Angka untuk keterangan di bawah tabel.
     *
     * @return array{clients: int, tasks: int, enabled: int}
     */
    public function getStats(): array
    {
        return [
            'clients' => $this->getClients()->count(),
            'tasks' => $this->getTasks()->count(),
            'enabled' => $this->getScheduleMap()->where('is_enabled', true)->count(),
        ];
    }

    /**
     * Klik sel: aktif ⇄ nonaktif. Sel kosong tidak bisa di-toggle — schedule
     * baru harus dibuat lewat form supaya ekspresi cron-nya eksplisit.
     */
    public function toggle(int $clientId, int $taskId): void
    {
        $schedule = Schedule::where('client_id', $clientId)
            ->where('task_template_id', $taskId)
            ->first();

        if ($schedule === null) {
            Notification::make()
                ->title('No schedule yet')
                ->body('Create the schedule from the Schedules menu first, so its cron expression is explicit.')
                ->warning()
                ->send();

            return;
        }

        if (! auth()->user()->can('toggle', $schedule)) {
            Notification::make()->title('Not allowed.')->danger()->send();

            return;
        }

        $schedule->is_enabled = ! $schedule->is_enabled;
        $schedule->recalculateNextRun();
        $schedule->save();

        $this->flushCaches();
    }

    public function toggleClientRow(int $clientId, bool $enabled): void
    {
        $this->bulkToggle(Schedule::where('client_id', $clientId), $enabled);
    }

    public function toggleTaskColumn(int $taskId, bool $enabled): void
    {
        $this->bulkToggle(Schedule::where('task_template_id', $taskId), $enabled);
    }

    /**
     * @return Collection<int, Client>
     */
    private function getClients(): Collection
    {
        return $this->clientCache ??= Client::query()
            ->when($this->clientSearch !== '', fn ($q) => $q->where(function ($q) {
                $q->where('code', 'like', '%'.$this->clientSearch.'%')
                    ->orWhere('name', 'like', '%'.$this->clientSearch.'%');
            }))
            ->when($this->onlyEnabled, fn ($q) => $q->whereHas(
                'schedules',
                fn ($q) => $q->where('is_enabled', true)
            ))
            ->orderBy('code')
            ->get();
    }

    /**
     * Peta "client_id:task_template_id" => schedule, agar view tidak query per sel.
     *
     * @return Collection<string, Schedule>
     */
    private function getScheduleMap(): Collection
    {
        return $this->scheduleCache ??= Schedule::query()
            ->get()
            ->keyBy(fn (Schedule $s) => $s->client_id.':'.$s->task_template_id);
    }

    /**
     * @return array{task_id: int, state: string, readout: string, tooltip: string, url: string}
     */
    private function buildCell(Client $client, TaskTemplate $task, ?Schedule $schedule): array
    {
        $heading = $client->code.' / '.$task->key;

        if ($schedule === null) {
            return [
                'task_id' => $task->id,
                'state' => self::STATE_MISSING,
                'readout' => $heading.'  ·  no schedule yet — click to create one',
                'tooltip' => $heading."\nNo schedule yet — click to create one",
                'url' => ScheduleResource::getUrl('create', [
                    'client_id' => $client->id,
                    'task_template_id' => $task->id,
                ]),
            ];
        }

        $next = $schedule->next_run_at
            ? 'next '.$schedule->next_run_at->setTimezone($schedule->timezone)->format('D, d M H:i')
            : null;

        $parts = array_filter([
            $heading,
            $schedule->cron_expression,
            $next,
            'lock '.$schedule->lock_key,
            $schedule->is_enabled ? 'enabled' : 'disabled',
            $schedule->needs_review ? '⚠ needs review' : null,
        ]);

        return [
            'task_id' => $task->id,
            'state' => $schedule->is_enabled ? self::STATE_ENABLED : self::STATE_DISABLED,
            'readout' => implode('  ·  ', $parts),
            'tooltip' => implode("\n", $parts),
            'url' => ScheduleResource::getUrl('edit', ['record' => $schedule->getKey()]),
        ];
    }

    private function bulkToggle($query, bool $enabled): void
    {
        if (! $this->canOperate()) {
            Notification::make()->title('Not allowed.')->danger()->send();

            return;
        }

        $count = 0;

        foreach ($query->get() as $schedule) {
            $schedule->is_enabled = $enabled;
            $schedule->recalculateNextRun();
            $schedule->save();
            $count++;
        }

        $this->flushCaches();

        Notification::make()
            ->title($count.' schedule'.($count === 1 ? '' : 's').' '.($enabled ? 'enabled' : 'disabled'))
            ->success()
            ->send();
    }

    /**
     * Cache hanya berlaku satu request; setelah menulis, render berikutnya di
     * request yang sama harus membaca ulang dari database.
     */
    private function flushCaches(): void
    {
        $this->clientCache = null;
        $this->taskCache = null;
        $this->scheduleCache = null;
    }
}
