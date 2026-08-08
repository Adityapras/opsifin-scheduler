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
                    ->label('Schedule')
                    ->fontFamily('mono')
                    ->description(function (Schedule $record) {
                        $describer = app(CronDescriber::class);
                        $text = $describer->describe($record->cron_expression);

                        // Tanpa penanda ini, "*/59" terbaca sebagai "setiap 59 menit"
                        // padahal jedanya 59 menit lalu 1 menit (BUG-5 di rencana).
                        return $describer->intervalWarning($record->cron_expression)
                            ? $text.' ⚠ uneven interval'
                            : $text;
                    })
                    ->searchable(),

                TextColumn::make('next_run_at')
                    ->label('Next run')
                    ->dateTime('d M H:i')
                    ->timezone(config('opsifin_cron.default_timezone'))
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('last_run_at')
                    ->label('Last run')
                    ->since()
                    ->placeholder('never')
                    ->sortable(),

                TextColumn::make('latest_run_status')
                    ->label('Last result')
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
                    ->label('Enabled')
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

                TernaryFilter::make('is_enabled')->label('Enabled'),

                TernaryFilter::make('needs_review')->label('Needs review'),

                SelectFilter::make('legacy_pattern')
                    ->label('Origin')
                    ->options(collect(LegacyPattern::cases())->mapWithKeys(fn ($p) => [$p->value => $p->label()])),
            ])
            ->recordActions([
                Action::make('dryRun')
                    ->label('Dry run')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->authorize(fn (Schedule $record) => auth()->user()->can('dryRun', $record))
                    ->modalHeading('Request that would be sent')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(function (Schedule $record, JobRunner $runner) {
                        $run = $runner->run($record, RunTrigger::DryRun, dryRun: true);

                        // Baris yang tersimpan di `runs` selalu tersamar. Yang boleh
                        // mengelola data master melihat nilai aslinya di layar, karena
                        // gunanya memang mencocokkan kredensial dengan script legacy.
                        $text = auth()->user()->canManage()
                            ? $runner->describeRequest($record->resolveRequest(), maskSecrets: false)
                            : ($run->response_excerpt ?? '');

                        return new HtmlString(
                            '<pre class="overflow-x-auto whitespace-pre-wrap rounded bg-gray-950/5 p-3 text-xs dark:bg-white/5">'
                            .e($text)
                            .'</pre>'
                        );
                    }),

                Action::make('runNow')
                    ->label('Run now')
                    ->icon('heroicon-o-play')
                    ->color('primary')
                    ->authorize(fn (Schedule $record) => auth()->user()->can('run', $record))
                    ->requiresConfirmation()
                    ->modalHeading('Run now?')
                    ->modalDescription(fn (Schedule $record) => 'The endpoint '.$record->resolveRequest()['url'].' will actually be called. The lock is still honoured.')
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
                        ->label('Enable')
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
                        ->label('Disable')
                        ->icon('heroicon-o-x-mark')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('Disabled schedules disappear from the crontab on the next deploy.')
                        ->authorize(fn () => auth()->user()->canOperate())
                        ->action(fn (Collection $records) => $records->each->update(['is_enabled' => false]))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('clearReview')
                        ->label('Mark as reviewed')
                        ->icon('heroicon-o-shield-check')
                        ->requiresConfirmation()
                        ->authorize(fn () => auth()->user()->canManage())
                        ->action(fn (Collection $records) => $records->each->update(['needs_review' => false]))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }
}
