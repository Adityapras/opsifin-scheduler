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
                    ->label('Schedule')
                    ->counts('schedules')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('overrides_count')
                    ->label('Override')
                    ->counts('overrides')
                    ->alignEnd()
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray')
                    ->tooltip('Client yang menyimpang dari nilai default template ini')
                    ->sortable(),

                IconColumn::make('legacy_gateway_routed')
                    ->label('Gateway')
                    ->boolean()
                    ->tooltip(fn (TaskTemplate $record) => $record->legacy_job_file),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Status aktif'),

                TernaryFilter::make('legacy_gateway_routed')->label('Berasal dari gateway'),

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
