<?php

namespace App\Filament\Pages;

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
 */
class ScheduleMatrix extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static ?string $navigationLabel = 'Matrix';

    protected static ?string $title = 'Matrix client × task';

    protected static ?int $navigationSort = 5;

    protected static string|UnitEnum|null $navigationGroup = 'Operasional';

    protected string $view = 'filament.pages.schedule-matrix';

    public string $clientSearch = '';

    public string $taskSearch = '';

    public bool $onlyEnabled = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->is_active ?? false;
    }

    /**
     * @return Collection<int, Client>
     */
    public function getClients(): Collection
    {
        return Client::query()
            ->when($this->clientSearch !== '', fn ($q) => $q->where(function ($q) {
                $q->where('code', 'like', '%'.$this->clientSearch.'%')
                    ->orWhere('name', 'like', '%'.$this->clientSearch.'%');
            }))
            ->orderBy('code')
            ->get();
    }

    /**
     * @return Collection<int, TaskTemplate>
     */
    public function getTasks(): Collection
    {
        return TaskTemplate::query()
            ->when($this->taskSearch !== '', fn ($q) => $q->where('key', 'like', '%'.$this->taskSearch.'%'))
            ->orderBy('key')
            ->get();
    }

    /**
     * Peta "client_id:task_template_id" => schedule, agar view tidak query per sel.
     *
     * @return Collection<string, Schedule>
     */
    public function getMatrix(): Collection
    {
        return Schedule::query()
            ->when($this->onlyEnabled, fn ($q) => $q->where('is_enabled', true))
            ->get()
            ->keyBy(fn (Schedule $s) => $s->client_id.':'.$s->task_template_id);
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
                ->title('Belum ada schedule')
                ->body('Buat schedule lebih dulu lewat menu Schedules agar ekspresi cron-nya jelas.')
                ->warning()
                ->send();

            return;
        }

        if (! auth()->user()->can('toggle', $schedule)) {
            Notification::make()->title('Tidak punya izin.')->danger()->send();

            return;
        }

        $schedule->is_enabled = ! $schedule->is_enabled;
        $schedule->recalculateNextRun();
        $schedule->save();
    }

    public function toggleClientRow(int $clientId, bool $enabled): void
    {
        $this->bulkToggle(Schedule::where('client_id', $clientId), $enabled);
    }

    public function toggleTaskColumn(int $taskId, bool $enabled): void
    {
        $this->bulkToggle(Schedule::where('task_template_id', $taskId), $enabled);
    }

    private function bulkToggle($query, bool $enabled): void
    {
        if (! auth()->user()->canOperate()) {
            Notification::make()->title('Tidak punya izin.')->danger()->send();

            return;
        }

        $count = 0;

        foreach ($query->get() as $schedule) {
            $schedule->is_enabled = $enabled;
            $schedule->recalculateNextRun();
            $schedule->save();
            $count++;
        }

        Notification::make()
            ->title($count.' schedule '.($enabled ? 'diaktifkan' : 'dinonaktifkan'))
            ->success()
            ->send();
    }
}
