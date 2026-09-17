<?php

namespace App\Filament\Resources\Runs\Tables;

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\Run;
use App\Services\Maintenance\RunLogDeleter;
use App\Services\Scheduling\QueuedRunCanceller;
use App\Services\Scheduling\RunDispatcher;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class RunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['client', 'taskTemplate']))
            ->defaultSort('scheduled_for', 'desc')->poll('15s')->persistFiltersInSession()
            ->columns([
                TextColumn::make('scheduled_for')->label('Occurrence')->dateTime('d M H:i:s')
                    ->timezone(config('opsifin_cron.default_timezone'))->sortable(),
                TextColumn::make('client.code')->label('Client')->searchable()->sortable()->placeholder('Deleted'),
                TextColumn::make('taskTemplate.key')->label('Task')->searchable()->sortable()->placeholder('Deleted'),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (RunStatus $state) => $state->label())
                    ->color(fn (RunStatus $state) => $state->color()),
                TextColumn::make('trigger')->badge()->color('gray')
                    ->formatStateUsing(fn (RunTrigger $state) => $state->label()),
                TextColumn::make('http_status')->label('HTTP')->badge()->placeholder('—')
                    ->color(fn (?int $state) => $state !== null && $state < 400 ? 'success' : 'danger'),
                TextColumn::make('duration_ms')->label('Duration')->alignEnd()->sortable()
                    ->formatStateUsing(fn (?int $state) => $state === null ? '—' : number_format($state).' ms'),
                TextColumn::make('start_lag_ms')->label('Start lag')->alignEnd()->sortable()
                    ->formatStateUsing(fn (?int $state) => $state === null ? '—' : number_format($state).' ms'),
                TextColumn::make('error_message')->label('Message')->limit(55)->tooltip(fn (Run $record) => $record->error_message)->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('client_id')->relationship('client', 'code')->searchable()->preload()->multiple(),
                SelectFilter::make('task_template_id')->label('Task')->relationship('taskTemplate', 'key')->searchable()->preload()->multiple(),
                SelectFilter::make('status')->options(collect(RunStatus::cases())->mapWithKeys(fn ($v) => [$v->value => $v->label()]))->multiple(),
                SelectFilter::make('trigger')->options(collect(RunTrigger::cases())->mapWithKeys(fn ($v) => [$v->value => $v->label()]))->multiple(),
                Filter::make('problems_only')->label('Problems only')
                    ->query(fn ($query) => $query->where('status', RunStatus::Failed->value)),
                Filter::make('period')->schema([
                    DateTimePicker::make('from'), DateTimePicker::make('until'),
                ])->query(fn ($query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $value) => $q->where('scheduled_for', '>=', $value))
                    ->when($data['until'] ?? null, fn ($q, $value) => $q->where('scheduled_for', '<=', $value))),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('cancel')->icon('heroicon-o-x-circle')->color('danger')->requiresConfirmation()
                        ->modalHeading('Cancel waiting run?')
                        ->modalDescription('Cancel this occurrence before execution starts.')
                        ->visible(fn (Run $record) => in_array($record->status, [RunStatus::Queued, RunStatus::Pending], true))
                        ->authorize(fn (Run $record) => auth()->user()->can('cancel', $record))
                        ->action(function (Run $record, QueuedRunCanceller $canceller): void {
                            try {
                                $canceller->cancel($record);
                                Notification::make()->title('Run #'.$record->id.' cancelled')->success()->send();
                            } catch (InvalidArgumentException) {
                                Notification::make()->title('Run #'.$record->id.' is no longer waiting')->warning()->send();
                            }
                        }),
                    Action::make('retry')->icon('heroicon-o-arrow-path')->color('warning')->requiresConfirmation()
                        ->visible(fn (Run $record) => config('opsifin_cron.execution_driver') === 'queue' && $record->execution_driver !== 'direct' && $record->status === RunStatus::Failed && $record->schedule_id !== null)
                        ->authorize(fn (Run $record) => auth()->user()->can('retry', $record))
                        ->action(function (Run $record, RunDispatcher $dispatcher): void {
                            $retry = $dispatcher->retry($record);
                            Notification::make()->title('Retry occurrence #'.$retry->id.' queued')->success()->send();
                        }),
                    ViewAction::make(),
                    Action::make('delete')->label('Delete log')->icon('heroicon-o-trash')->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Delete this execution log?')
                        ->modalDescription('The record is removed permanently. The deletion itself is written to Audit history.')
                        ->modalSubmitActionLabel('Delete')
                        ->visible(fn (Run $record) => $record->status->isTerminal())
                        ->authorize(fn (Run $record) => auth()->user()->can('delete', $record))
                        ->action(function (Run $record, RunLogDeleter $deleter): void {
                            try {
                                $deleter->delete($record);
                                Notification::make()->title('Execution log #'.$record->id.' deleted')->success()->send();
                            } catch (InvalidArgumentException) {
                                Notification::make()->title('Run #'.$record->id.' is running and cannot be deleted')->warning()->send();
                            }
                        }),
                ])->label('Actions')->tooltip('Actions')->color('gray'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('cancelQueued')
                        ->label('Cancel waiting runs')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('Cancel selected occurrences that are still waiting to start.')
                        ->authorize(fn (): bool => auth()->user()->canOperate())
                        ->action(function (Collection $records, QueuedRunCanceller $canceller): void {
                            $cancelled = 0;

                            foreach ($records as $record) {
                                if (! in_array($record->status, [RunStatus::Queued, RunStatus::Pending], true)) {
                                    continue;
                                }

                                try {
                                    $canceller->cancel($record);
                                    $cancelled++;
                                } catch (InvalidArgumentException) {
                                    // The worker claimed it after the table was rendered.
                                }
                            }

                            Notification::make()
                                ->title($cancelled.' waiting run(s) cancelled')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('deleteLogs')
                        ->label('Delete selected logs')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Delete selected execution logs?')
                        ->modalDescription('Finished occurrences are removed permanently. Runs still waiting or running are skipped.')
                        ->modalSubmitActionLabel('Delete')
                        ->authorize(fn (): bool => auth()->user()->can('deleteAny', Run::class))
                        ->action(function (Collection $records, RunLogDeleter $deleter): void {
                            $result = $deleter->deleteMany($records);

                            Notification::make()
                                ->title($result['deleted'].' execution log(s) deleted')
                                ->body($result['skipped'] > 0 ? $result['skipped'].' skipped because they are still waiting or running.' : null)
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }
}
