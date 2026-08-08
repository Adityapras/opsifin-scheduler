<?php

namespace App\Filament\Resources\TaskTemplates\Tables;

use App\Models\TaskTemplate;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class TaskTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('key')
            ->columns([
                TextColumn::make('key')
                    ->label('Key')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('http_method')
                    ->label('Method')
                    ->badge()
                    ->color(fn ($state) => $state->value === 'GET' ? 'info' : 'warning'),

                TextColumn::make('path_template')
                    ->label('Path')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->limit(45),

                TextColumn::make('default_timeout_sec')
                    ->label('Timeout')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => $state.'s'),

                TextColumn::make('schedules_count')
                    ->label('Schedules')
                    ->counts('schedules')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('overrides_count')
                    ->label('Overrides')
                    ->counts('overrides')
                    ->alignEnd()
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray')
                    ->tooltip('Clients that deviate from this template\'s defaults')
                    ->sortable(),

                IconColumn::make('legacy_gateway_routed')
                    ->label('Gateway')
                    ->boolean()
                    ->tooltip(fn (TaskTemplate $record) => $record->legacy_job_file),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),

                TernaryFilter::make('legacy_gateway_routed')->label('Came from gateway'),

                SelectFilter::make('http_method')
                    ->label('Method')
                    ->options(['GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH', 'DELETE' => 'DELETE']),
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
