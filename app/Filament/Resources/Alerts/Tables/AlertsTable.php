<?php

namespace App\Filament\Resources\Alerts\Tables;

use App\Enums\AlertCondition;
use App\Enums\AlertStatus;
use App\Models\Alert;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class AlertsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['client', 'taskTemplate', 'alertRule']))
            ->defaultSort('fired_at', 'desc')
            ->poll('60s')
            ->persistFiltersInSession()
            ->columns([
                TextColumn::make('fired_at')
                    ->label('Fired')
                    ->dateTime('d M H:i')
                    ->timezone(config('opsifin_cron.default_timezone'))
                    ->sortable(),

                TextColumn::make('client.code')
                    ->label('Client')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('taskTemplate.key')
                    ->label('Task')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('condition')
                    ->label('Condition')
                    ->badge()
                    ->formatStateUsing(fn (AlertCondition $state) => $state->label())
                    ->color('gray'),

                TextColumn::make('title')
                    ->label('Alert')
                    ->description(fn (Alert $record) => $record->body)
                    ->wrap()
                    ->searchable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (AlertStatus $state) => $state->label())
                    ->color(fn (AlertStatus $state) => $state->color()),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(AlertStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]))
                    ->default([AlertStatus::Open->value])
                    ->multiple(),

                SelectFilter::make('condition')
                    ->label('Condition')
                    ->options(collect(AlertCondition::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                    ->multiple(),

                SelectFilter::make('client_id')
                    ->label('Client')
                    ->relationship('client', 'code')
                    ->searchable()
                    ->preload()
                    ->multiple(),

                Filter::make('last_24h')
                    ->label('Last 24 hours')
                    ->query(fn ($query) => $query->where('fired_at', '>=', now()->subDay())),
            ])
            ->recordActions([
                Action::make('acknowledge')
                    ->label('Acknowledge')
                    ->icon('heroicon-o-hand-raised')
                    ->color('warning')
                    ->visible(fn (Alert $record) => $record->status === AlertStatus::Open)
                    ->authorize(fn (Alert $record) => auth()->user()->can('acknowledge', $record))
                    ->action(fn (Alert $record) => $record->update([
                        'status' => AlertStatus::Acknowledged,
                        'acknowledged_at' => now(),
                        'acknowledged_by' => auth()->id(),
                    ])),

                Action::make('resolve')
                    ->label('Resolve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Alert $record) => $record->status !== AlertStatus::Resolved)
                    ->authorize(fn (Alert $record) => auth()->user()->can('resolve', $record))
                    ->action(fn (Alert $record) => $record->update([
                        'status' => AlertStatus::Resolved,
                        'resolved_at' => now(),
                    ])),

                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('resolveSelected')
                        ->label('Resolve')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->authorize(fn () => auth()->user()->canOperate())
                        ->action(fn (Collection $records) => $records->each->update([
                            'status' => AlertStatus::Resolved,
                            'resolved_at' => now(),
                        ]))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }
}
