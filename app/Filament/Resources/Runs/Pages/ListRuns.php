<?php

namespace App\Filament\Resources\Runs\Pages;

use App\Filament\Resources\Runs\RunResource;
use App\Models\Run;
use App\Services\Maintenance\RetentionService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListRuns extends ListRecords
{
    protected static string $resource = RunResource::class;

    /**
     * Riwayat eksekusi tidak bisa dibuat manual — hanya runner yang menulis
     * tabel runs. Satu-satunya aksi halaman adalah pembersihan berdasarkan umur,
     * padanan manual dari `cron:purge-runs` yang berjalan tiap pukul 03:00.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('purgeOldLogs')
                ->label('Delete old logs')
                ->icon('heroicon-o-archive-box-x-mark')
                ->color('danger')
                ->authorize(fn (): bool => auth()->user()->can('deleteAny', Run::class))
                ->schema([
                    TextInput::make('days')
                        ->label('Keep the last')
                        ->suffix('days')
                        ->helperText('Finished occurrences older than this are deleted. Waiting and running occurrences are never touched.')
                        ->numeric()->minValue(1)->maxValue(3650)->required()
                        ->default(fn () => (int) config('opsifin_cron.runs_retention_days')),
                ])
                ->requiresConfirmation()
                ->modalIcon('heroicon-o-archive-box-x-mark')
                ->modalHeading('Delete old execution logs')
                ->modalSubmitActionLabel('Delete')
                ->action(function (array $data, RetentionService $retention): void {
                    $days = max(1, (int) $data['days']);

                    if ($retention->count($days) === 0) {
                        Notification::make()->title('No execution log is older than '.$days.' day(s)')->info()->send();

                        return;
                    }

                    $deleted = $retention->purge($days);

                    Notification::make()
                        ->title(number_format($deleted).' execution log(s) deleted')
                        ->body('Older than '.$days.' day(s).')
                        ->success()
                        ->send();
                }),
        ];
    }
}
