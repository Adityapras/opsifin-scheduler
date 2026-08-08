<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Schedules\ScheduleResource;
use App\Models\Schedule;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Schedule aktif yang jadwalnya sudah lewat tapi belum tereksekusi.
 *
 * Berbeda dari daftar run gagal: yang muncul di sini justru job yang tidak
 * meninggalkan jejak apa pun — biasanya karena crontab belum di-deploy atau
 * daemon cron mati.
 */
class LateSchedulesTable extends TableWidget
{
    protected static ?int $sort = 2;

    protected ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->is_active ?? false;
    }

    public function getTableHeading(): string
    {
        return 'Overdue schedules';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->overdueQuery())
            ->defaultSort('next_run_at')
            ->paginationPageOptions([5, 10, 25])
            ->emptyStateHeading('Nothing overdue')
            ->emptyStateDescription('Every enabled schedule has run at or after its last due time.')
            ->columns([
                TextColumn::make('client.code')->label('Client')->weight('bold'),
                TextColumn::make('taskTemplate.key')->label('Task'),

                TextColumn::make('cron_expression')
                    ->label('Schedule')
                    ->fontFamily('mono'),

                TextColumn::make('next_run_at')
                    ->label('Was due')
                    ->dateTime('d M H:i')
                    ->timezone(config('opsifin_cron.default_timezone'))
                    ->color('danger'),

                TextColumn::make('last_run_at')
                    ->label('Last run')
                    ->since()
                    ->placeholder('never'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Schedule $record) => ScheduleResource::getUrl('edit', ['record' => $record])),
            ]);
    }

    /**
     * next_run_at menyimpan jadwal berikutnya yang belum terjadi. Kalau nilainya
     * sudah lewat, artinya runner tidak pernah menyentuh schedule ini sejak
     * jadwal itu — karena setiap run menghitung ulang kolomnya.
     *
     * @return Builder<Schedule>
     */
    private function overdueQuery(): Builder
    {
        return Schedule::query()
            ->with(['client', 'taskTemplate'])
            ->where('is_enabled', true)
            ->whereHas('client', fn ($q) => $q->where('is_active', true))
            ->whereHas('taskTemplate', fn ($q) => $q->where('is_active', true))
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<', now()->subMinutes(5));
    }
}
