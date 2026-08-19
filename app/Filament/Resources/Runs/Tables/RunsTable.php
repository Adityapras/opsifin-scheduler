<?php

namespace App\Filament\Resources\Runs\Tables;

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\Run;
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
                        ->modalHeading('Cancel queued run?')
                        ->modalDescription('The queue payload will be removed. Runs that have already started cannot be cancelled here.')
                        ->visible(fn (Run $record) => $record->status === RunStatus::Queued)
                        ->authorize(fn (Run $record) => auth()->user()->can('cancel', $record))
                        ->action(function (Run $record, QueuedRunCanceller $canceller): void {
                            try {
                                $canceller->cancel($record);
                                Notification::make()->title('Run #'.$record->id.' cancelled')->success()->send();
                            } catch (InvalidArgumentException) {
                                Notification::make()->title('Run #'.$record->id.' is no longer queued')->warning()->send();
                            }
                        }),
                    Action::make('retry')->icon('heroicon-o-arrow-path')->color('warning')->requiresConfirmation()
                        ->visible(fn (Run $record) => $record->status === RunStatus::Failed && $record->schedule_id !== null)
                        ->authorize(fn (Run $record) => auth()->user()->can('retry', $record))
                        ->action(function (Run $record, RunDispatcher $dispatcher): void {
                            $retry = $dispatcher->retry($record);
                            Notification::make()->title('Retry occurrence #'.$retry->id.' queued')->success()->send();
                        }),
                    ViewAction::make(),
                ])->label('Actions')->tooltip('Actions')->color('gray'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('cancelQueued')
                        ->label('Cancel queued runs')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('Only selected runs that are still queued will be cancelled. Running and completed runs are left untouched.')
                        ->authorize(fn (): bool => auth()->user()->canOperate())
                        ->action(function (Collection $records, QueuedRunCanceller $canceller): void {
                            $cancelled = 0;

                            foreach ($records as $record) {
                                if ($record->status !== RunStatus::Queued) {
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
                                ->title($cancelled.' queued run(s) cancelled')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }
}
