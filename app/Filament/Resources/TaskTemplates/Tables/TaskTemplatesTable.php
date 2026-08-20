<?php

namespace App\Filament\Resources\TaskTemplates\Tables;

use App\Models\Client;
use App\Models\TaskTemplate;
use App\Services\Scheduling\ScheduleManager;
use Cron\CronExpression;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class TaskTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('key')
            ->columns([
                TextColumn::make('key')->searchable()->sortable()->weight('bold')
                    ->description(fn (TaskTemplate $record) => $record->name),
                TextColumn::make('config.method')->label('Method')->badge()->color('info'),
                TextColumn::make('config.path')->label('Endpoint path')->fontFamily('mono')->searchable()->copyable()->limit(52),
                TextColumn::make('default_cron_expression')
                    ->label('Default timing')
                    ->fontFamily('mono')
                    ->description(fn (TaskTemplate $record) => $record->auto_assign_to_new_clients ? 'Auto-assign' : 'Manual assignment'),
                TextColumn::make('timeout_sec')->label('Timeout')->suffix(' sec')->alignEnd()->sortable(),
                TextColumn::make('schedules_count')->label('Assignments')->counts('schedules')->alignEnd()->sortable(),
                IconColumn::make('auto_assign_to_new_clients')
                    ->label('New clients')
                    ->boolean()
                    ->trueColor('primary')
                    ->falseIcon('heroicon-o-minus'),
                IconColumn::make('needs_review')->label('Review')->boolean()->trueColor('warning')->falseIcon('heroicon-o-minus'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active'),
                TernaryFilter::make('auto_assign_to_new_clients')->label('Auto-assign to new clients'),
                TernaryFilter::make('needs_review'),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('assignAllActive')
                        ->label('Assign all active clients')->icon('heroicon-o-user-group')->color('success')
                        ->authorize(fn () => auth()->user()->canManage())
                        ->schema(self::timingSchema())
                        ->requiresConfirmation()
                        ->action(function (TaskTemplate $record, array $data, ScheduleManager $manager): void {
                            $ids = Client::query()->where('is_active', true)->pluck('id')->all();
                            $count = $manager->assign($record, $ids, $data['cron_expression'], $data['timezone'], (bool) $data['is_enabled']);
                            Notification::make()->title($count.' new assignment(s) created')->success()->send();
                        }),
                    Action::make('assignSelected')
                        ->label('Assign selected clients')->icon('heroicon-o-user-plus')
                        ->authorize(fn () => auth()->user()->canManage())
                        ->schema([
                            Select::make('client_ids')->label('Clients')
                                ->options(Client::query()->orderBy('code')->pluck('name', 'id'))
                                ->getOptionLabelUsing(fn ($value) => (($client = Client::find($value)) ? $client->code.' — '.$client->name : $value))
                                ->multiple()->searchable()->preload()->required(),
                            ...self::timingSchema(),
                        ])
                        ->action(function (TaskTemplate $record, array $data, ScheduleManager $manager): void {
                            $count = $manager->assign($record, $data['client_ids'], $data['cron_expression'], $data['timezone'], (bool) $data['is_enabled']);
                            Notification::make()->title($count.' new assignment(s) created')->success()->send();
                        }),
                    Action::make('removeSelected')
                        ->label('Remove from selected clients')->icon('heroicon-o-user-minus')->color('danger')
                        ->authorize(fn () => auth()->user()->canManage())
                        ->schema([
                            Select::make('client_ids')->label('Clients')
                                ->options(Client::query()->orderBy('code')->pluck('name', 'id'))
                                ->multiple()->searchable()->preload()->required(),
                        ])
                        ->requiresConfirmation()
                        ->modalDescription('Assignments with an active run are kept. Run history remains available after other assignments are removed.')
                        ->action(function (TaskTemplate $record, array $data, ScheduleManager $manager): void {
                            $count = $manager->removeAssignments($record, $data['client_ids']);
                            Notification::make()->title($count.' assignment(s) removed')->success()->send();
                        }),
                    EditAction::make(),
                ])->label('Actions')->tooltip('Actions')->color('gray'),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    /** @return array<int, mixed> */
    private static function timingSchema(): array
    {
        return [
            TextInput::make('cron_expression')
                ->label('Cron expression')
                ->default(fn (?TaskTemplate $record): string => $record?->default_cron_expression ?: '*/5 * * * *')
                ->required()
                ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! CronExpression::isValidExpression((string) $value)) {
                        $fail('The cron expression is not valid.');
                    }
                }),
            Select::make('timezone')
                ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                ->searchable()->required()->default(config('opsifin_cron.default_timezone')),
            Toggle::make('is_enabled')
                ->label('Enable new assignments immediately')
                ->helperText('Keep this off during import and cutover preparation.')
                ->default(fn (?TaskTemplate $record): bool => (bool) ($record?->default_schedule_enabled ?? false)),
        ];
    }
}
