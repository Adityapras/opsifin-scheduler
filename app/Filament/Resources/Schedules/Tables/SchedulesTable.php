<?php

namespace App\Filament\Resources\Schedules\Tables;

use App\Enums\RunStatus;
use App\Models\Schedule;
use App\Services\CronDescriber;
use App\Services\Execution\HttpExecutor;
use App\Services\Scheduling\RunDispatcher;
use App\Services\Scheduling\ScheduleManager;
use Cron\CronExpression;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use Throwable;

class SchedulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['client', 'taskTemplate']))
            ->defaultSort('id', 'desc')->poll('30s')->persistFiltersInSession()
            ->columns([
                TextColumn::make('client.code')->label('Client')->searchable()->sortable()->weight('bold'),
                TextColumn::make('taskTemplate.key')->label('Job')->searchable()->sortable(),
                TextColumn::make('cron_expression')->label('Cron')->fontFamily('mono')->searchable()
                    ->description(fn (Schedule $record) => app(CronDescriber::class)->describe($record->cron_expression)),
                TextColumn::make('next_run_at')->label('Next run')->dateTime('d M H:i')
                    ->timezone(config('opsifin_cron.default_timezone'))->placeholder('Paused')->sortable(),
                TextColumn::make('latest_result')->label('Latest')
                    ->state(fn (Schedule $record) => $record->runs()->latest('scheduled_for')->value('status'))
                    ->badge()
                    ->formatStateUsing(fn (RunStatus|string|null $state) => self::runStatus($state)?->label() ?? 'No runs')
                    ->color(fn (RunStatus|string|null $state) => self::runStatus($state)?->color() ?? 'gray'),
                IconColumn::make('prevent_overlap')->label('No overlap')->boolean()
                    ->trueIcon('heroicon-o-lock-closed')->trueColor('success')
                    ->falseIcon('heroicon-o-lock-open')->falseColor('warning')
                    ->tooltip(fn (Schedule $record) => $record->prevent_overlap
                        ? 'A new occurrence is skipped while the previous run is active.'
                        : 'Overlapping runs are allowed.'),
                IconColumn::make('needs_review')->label('Review')->boolean()->trueColor('warning')->falseIcon('heroicon-o-minus'),
                IconColumn::make('is_enabled')->label('Enabled')->boolean()
                    ->action(Action::make('toggleEnabled')
                        ->authorize(fn (Schedule $record) => auth()->user()->can('toggle', $record))
                        ->action(fn (Schedule $record, ScheduleManager $manager) => $manager->setEnabled($record, ! $record->is_enabled))),
            ])
            ->filters([
                SelectFilter::make('client_id')->relationship('client', 'code')->searchable()->preload()->multiple(),
                SelectFilter::make('task_template_id')->label('Job')->relationship('taskTemplate', 'key')->searchable()->preload()->multiple(),
                TernaryFilter::make('is_enabled'),
                TernaryFilter::make('needs_review'),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('inspect')
                        ->label('Inspect request')->icon('heroicon-o-magnifying-glass')->color('gray')
                        ->authorize(fn (Schedule $record) => auth()->user()->can('dryRun', $record))
                        ->modalHeading(fn (Schedule $record) => $record->client->code.' / '.$record->taskTemplate->key)
                        ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                        ->modalContent(fn (Schedule $record) => self::requestPreview($record)),
                    Action::make('runNow')
                        ->label('Run now')->icon('heroicon-o-play')
                        ->authorize(fn (Schedule $record) => auth()->user()->can('run', $record))
                        ->requiresConfirmation()
                        ->modalDescription('Queues one manual run. The client and job template must still be active.')
                        ->action(function (Schedule $record, RunDispatcher $dispatcher): void {
                            $run = $dispatcher->manual($record);
                            Notification::make()->title('Run #'.$run->id.' queued')->success()->send();
                        }),
                    EditAction::make(),
                ])->label('Actions')->tooltip('Actions')->color('gray'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('setCron')->label('Set cron in bulk')->icon('heroicon-o-clock')
                        ->authorize(fn () => auth()->user()->canManage())
                        ->schema([
                            TextInput::make('cron_expression')->required()->default('*/5 * * * *')
                                ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                                    if (! CronExpression::isValidExpression((string) $value)) {
                                        $fail('The cron expression is not valid.');
                                    }
                                }),
                            Select::make('timezone')->label('Timezone')
                                ->placeholder('Keep each existing timezone')
                                ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                                ->searchable(),
                        ])
                        ->requiresConfirmation()
                        ->action(function (Collection $records, array $data, ScheduleManager $manager): void {
                            $manager->changeTimingBulk($records, $data['cron_expression'], $data['timezone'] ?? null);
                            Notification::make()->title($records->count().' schedule(s) updated')->success()->send();
                        })->deselectRecordsAfterCompletion(),
                    BulkAction::make('resume')->label('Resume selected')->icon('heroicon-o-play')->color('success')
                        ->requiresConfirmation()->authorize(fn () => auth()->user()->canOperate())
                        ->action(fn (Collection $records, ScheduleManager $manager) => $manager->setEnabledBulk($records, true))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('pause')->label('Pause selected')->icon('heroicon-o-pause')->color('danger')
                        ->requiresConfirmation()->authorize(fn () => auth()->user()->canOperate())
                        ->action(fn (Collection $records, ScheduleManager $manager) => $manager->setEnabledBulk($records, false))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    private static function requestPreview(Schedule $schedule): HtmlString
    {
        try {
            $executor = app(HttpExecutor::class);
            $request = $executor->resolve($schedule->taskTemplate, $schedule->client);
            $text = $executor->describe($request);
        } catch (Throwable $exception) {
            report($exception);
            $text = 'Unable to resolve the request. Check the client URL and template configuration.';
        }

        return new HtmlString('<pre class="overflow-x-auto whitespace-pre-wrap rounded-xl bg-gray-950 p-4 text-xs leading-6 text-gray-100">'.e($text).'</pre>');
    }

    private static function runStatus(RunStatus|string|null $state): ?RunStatus
    {
        return $state instanceof RunStatus ? $state : RunStatus::tryFrom((string) $state);
    }
}
