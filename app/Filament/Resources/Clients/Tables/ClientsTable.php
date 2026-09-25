<?php

namespace App\Filament\Resources\Clients\Tables;

use App\Filament\Resources\Clients\Actions\AssignJobsAction;
use App\Filament\Resources\Clients\Actions\DeleteClientAction;
use App\Filament\Resources\Clients\Actions\TestConnectionAction;
use App\Models\Client;
use App\Services\Maintenance\ClientDeleter;
use App\Services\Scheduling\DefaultScheduleProvisioner;
use App\Services\Scheduling\ScheduleManager;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->limit(30),

                TextColumn::make('base_url')
                    ->label('Base URL')
                    ->searchable()
                    ->copyable()
                    ->limit(40),

                TextColumn::make('auth_username')
                    ->label('Auth')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state, Client $record) => $record->auth_type->value.($state ? ' · '.$state : ''))
                    ->searchable(),

                TextColumn::make('schedules_count')
                    ->label('Schedules')
                    ->counts('schedules')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('active_schedules_count')
                    ->label('Enabled')
                    ->counts(['schedules as active_schedules_count' => fn ($q) => $q->where('is_enabled', true)])
                    ->alignEnd()
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'gray')
                    ->sortable(),

                IconColumn::make('needs_review')
                    ->label('Review')
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('warning')
                    ->falseIcon('heroicon-o-check-circle')
                    ->falseColor('gray')
                    ->tooltip(fn (Client $record) => $record->review_notes),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),

                TernaryFilter::make('needs_review')->label('Needs review'),

                Filter::make('has_active_schedules')
                    ->label('Has enabled schedules')
                    ->query(fn ($query) => $query->whereHas('schedules', fn ($q) => $q->where('is_enabled', true))),
            ])
            ->recordActions([
                ActionGroup::make([
                    TestConnectionAction::forRecord(),

                    AssignJobsAction::forRecord(),

                    EditAction::make(),
                    DeleteClientAction::make(),
                ])->label('Actions')->tooltip('Actions')->color('gray'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('createMissingSchedules')
                        ->label('Create missing schedules')
                        ->icon('heroicon-o-squares-plus')
                        ->color('primary')
                        ->requiresConfirmation()
                        ->modalDescription('For each selected client, creates the missing schedules of every active job set to "Assign to new clients", using the job\'s default cron. They are always created paused, even when the job is set to enable immediately. Existing schedules are left unchanged.')
                        ->authorize(fn () => auth()->user()->canManage())
                        ->action(function (Collection $records, DefaultScheduleProvisioner $provisioner): void {
                            $created = $records->sum(fn (Client $client): int => $provisioner->provisionMissing($client));

                            Notification::make()
                                ->title($created.' missing schedule(s) created')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('activate')
                        ->label('Activate')
                        ->icon('heroicon-o-check')
                        ->requiresConfirmation()
                        ->authorize(fn () => auth()->user()->canOperate())
                        ->action(fn (Collection $records, ScheduleManager $manager) => $records->each(function (Client $client) use ($manager): void {
                            $client->update(['is_active' => true]);
                            $client->schedules()->where('is_enabled', true)->get()
                                ->each(fn ($schedule) => $manager->setEnabled($schedule, true));
                        }))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('deactivate')
                        ->label('Deactivate')
                        ->icon('heroicon-o-x-mark')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('Every occurrence for the selected clients will stop being materialized. Existing queue items are skipped by the worker.')
                        ->authorize(fn () => auth()->user()->canOperate())
                        ->action(fn (Collection $records) => $records->each->update(['is_active' => false]))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('deleteClients')
                        ->label('Delete selected')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Delete selected clients?')
                        ->modalDescription('Only clients without schedules are deleted; delete their schedules first. Run history is kept and every deletion is written to Audit history.')
                        ->modalSubmitActionLabel('Delete')
                        ->authorize(fn () => auth()->user()->canManage())
                        ->action(function (Collection $records, ClientDeleter $deleter): void {
                            $result = $deleter->deleteMany($records);

                            Notification::make()
                                ->title($result['deleted'].' client(s) deleted')
                                ->body($result['skipped'] > 0 ? $result['skipped'].' skipped because they still have schedules.' : null)
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }
}
