<?php

namespace App\Filament\Resources\AlertRules\Tables;

use App\Enums\AlertCondition;
use App\Models\AlertRule;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class AlertRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['client', 'taskTemplate', 'schedule.client', 'schedule.taskTemplate']))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('condition')
                    ->label('Condition')
                    ->badge()
                    ->formatStateUsing(fn (AlertCondition $state) => $state->label())
                    ->color(fn (AlertCondition $state) => $state === AlertCondition::MissedRun ? 'warning' : 'danger'),

                TextColumn::make('scope')
                    ->label('Scope')
                    ->state(fn (AlertRule $record) => $record->scopeLabel())
                    ->color('gray'),

                TextColumn::make('threshold')
                    ->label('Threshold')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, AlertRule $record) => $record->condition->usesThreshold() ? $state.'×' : '—'),

                TextColumn::make('grace_minutes')
                    ->label('Grace')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, AlertRule $record) => $record->condition->usesGrace() ? $state.'m' : '—'),

                TextColumn::make('cooldown_minutes')
                    ->label('Cooldown')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => $state > 0 ? $state.'m' : 'none'),

                TextColumn::make('alerts_count')
                    ->label('Fired')
                    ->counts('alerts')
                    ->alignEnd()
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('condition')
                    ->label('Condition')
                    ->options(collect(AlertCondition::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                    ->multiple(),

                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
