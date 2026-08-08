<?php

namespace App\Filament\Resources\Schedules\Tables;

use App\Enums\LegacyPattern;
use App\Enums\RunTrigger;
use App\Models\Schedule;
use App\Services\CronDescriber;
use App\Services\Runner\JobRunner;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

class SchedulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['client', 'taskTemplate']))
            ->defaultSort('client.code')
            ->persistFiltersInSession()
            ->columns([
                TextColumn::make('client.code')
                    ->label('Client')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('taskTemplate.key')
                    ->label('Task')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('cron_expression')
                    ->label('Jadwal')
                    ->fontFamily('mono')
                    ->description(function (Schedule $record) {
                        $describer = app(CronDescriber::class);
                        $text = $describer->describe($record->cron_expression);

                        // Tanpa penanda ini, "*/59" terbaca sebagai "setiap 59 menit"
                        // padahal jedanya 59 menit lalu 1 menit (BUG-5 di rencana).
                        return $describer->intervalWarning($record->cron_expression)
                            ? $text.' ⚠ jeda tidak seragam'
                            : $text;
                    })
                    ->searchable(),

                TextColumn::make('next_run_at')
                    ->label('Berikutnya')
                    ->dateTime('d M H:i')
                    ->timezone(config('opsifin_cron.default_timezone'))
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('last_run_at')
                    ->label('Terakhir')
                    ->since()
                    ->placeholder('belum pernah')
                    ->sortable(),

                TextColumn::make('latest_run_status')
                    ->label('Hasil terakhir')
                    ->badge()
                    ->state(fn (Schedule $record) => $record->runs()->latest('started_at')->first()?->status)
                    ->formatStateUsing(fn ($state) => $state?->label() ?? '—')
                    ->color(fn ($state) => match ($state?->value) {
                        'success' => 'success',
                        'failed', 'timeout' => 'danger',
                        'skipped_lock' => 'warning',
                        'running' => 'info',
                        default => 'gray',
                    }),

                IconColumn::make('needs_review')
                    ->label('Review')
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('warning')
                    ->falseIcon('heroicon-o-minus')
                    ->falseColor('gray')
                    ->tooltip(fn (Schedule $record) => $record->review_notes),

                IconColumn::make('is_enabled')
                    ->label('Aktif')
                    ->boolean()
                    ->action(
                        Action::make('toggleEnabled')
                            ->authorize(fn (Schedule $record) => auth()->user()->can('toggle', $record))
                            ->action(fn (Schedule $record) => $record->update(['is_enabled' => ! $record->is_enabled])),
                    ),
            ])
            ->filters([
                SelectFilter::make('client_id')
                    ->label('Client')
                    ->relationship('client', 'code')
                    ->searchable()
                    ->preload()
                    ->multiple(),

                SelectFilter::make('task_template_id')
                    ->label('Task')
                    ->relationship('taskTemplate', 'key')
                    ->searchable()
                    ->preload()
                    ->multiple(),

                TernaryFilter::make('is_enabled')->label('Status aktif'),

                TernaryFilter::make('needs_review')->label('Butuh review'),

                SelectFilter::make('legacy_pattern')
                    ->label('Asal')
                    ->options(collect(LegacyPattern::cases())->mapWithKeys(fn ($p) => [$p->value => $p->label()])),
            ])
            ->recordActions([
                Action::make('dryRun')
                    ->label('Dry run')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->authorize(fn (Schedule $record) => auth()->user()->can('dryRun', $record))
                    ->modalHeading('Request yang akan dikirim')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->modalContent(function (Schedule $record, JobRunner $runner) {
                        $run = $runner->run($record, RunTrigger::DryRun, dryRun: true);

                        return new HtmlString(
                            '<pre class="overflow-x-auto whitespace-pre-wrap rounded bg-gray-950/5 p-3 text-xs dark:bg-white/5">'
                            .e($run->response_excerpt ?? '')
                            .'</pre>'
                        );
                    }),

                Action::make('runNow')
                    ->label('Run now')
                    ->icon('heroicon-o-play')
                    ->color('primary')
                    ->authorize(fn (Schedule $record) => auth()->user()->can('run', $record))
                    ->requiresConfirmation()
                    ->modalHeading('Jalankan sekarang?')
                    ->modalDescription(fn (Schedule $record) => 'Endpoint '.$record->resolveRequest()['url'].' akan benar-benar dipanggil. Lock tetap dihormati.')
                    ->action(function (Schedule $record, JobRunner $runner) {
                        $run = $runner->run($record, RunTrigger::Manual);

                        Notification::make()
                            ->title($record->client->code.' / '.$record->taskTemplate->key.' → '.$run->status->label())
                            ->body(trim(($run->http_status ? 'HTTP '.$run->http_status.'. ' : '').($run->error_message ?? '').' '.$run->duration_ms.' ms'))
                            ->status($run->status->isProblem() ? 'danger' : 'success')
                            ->persistent()
                            ->send();
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('enable')
                        ->label('Aktifkan')
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->requiresConfirmation()
                        ->authorize(fn () => auth()->user()->canOperate())
                        ->action(function (Collection $records) {
                            $records->each(function (Schedule $schedule) {
                                $schedule->is_enabled = true;
                                $schedule->recalculateNextRun();
                                $schedule->save();
                            });
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('disable')
                        ->label('Nonaktifkan')
                        ->icon('heroicon-o-x-mark')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('Schedule nonaktif hilang dari crontab pada deploy berikutnya.')
                        ->authorize(fn () => auth()->user()->canOperate())
                        ->action(fn (Collection $records) => $records->each->update(['is_enabled' => false]))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('clearReview')
                        ->label('Tandai sudah direview')
                        ->icon('heroicon-o-shield-check')
                        ->requiresConfirmation()
                        ->authorize(fn () => auth()->user()->canManage())
                        ->action(fn (Collection $records) => $records->each->update(['needs_review' => false]))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }
}
