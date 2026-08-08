<?php

namespace App\Filament\Resources\Clients\Tables;

use App\Models\Client;
use App\Services\ConnectionTester;
use Filament\Actions\Action;
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
                    ->label('Kode')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('name')
                    ->label('Nama')
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
                    ->label('Schedule')
                    ->counts('schedules')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('active_schedules_count')
                    ->label('Aktif')
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
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Status aktif'),

                TernaryFilter::make('needs_review')->label('Butuh review'),

                Filter::make('has_active_schedules')
                    ->label('Punya schedule aktif')
                    ->query(fn ($query) => $query->whereHas('schedules', fn ($q) => $q->where('is_enabled', true))),
            ])
            ->recordActions([
                Action::make('test')
                    ->label('Tes koneksi')
                    ->icon('heroicon-o-signal')
                    ->color('gray')
                    ->authorize(fn (Client $record) => auth()->user()->can('test', $record))
                    ->action(function (Client $record, ConnectionTester $tester) {
                        $result = $tester->test($record);

                        Notification::make()
                            ->title($record->code.' — '.$result['status'])
                            ->body($result['detail'].' ('.$result['duration_ms'].' ms)')
                            ->status($result['ok'] ? 'success' : 'danger')
                            ->persistent()
                            ->send();
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('activate')
                        ->label('Aktifkan')
                        ->icon('heroicon-o-check')
                        ->requiresConfirmation()
                        ->authorize(fn () => auth()->user()->canOperate())
                        ->action(fn (Collection $records) => $records->each->update(['is_active' => true]))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('deactivate')
                        ->label('Nonaktifkan')
                        ->icon('heroicon-o-x-mark')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('Client nonaktif tidak ikut di-render ke crontab pada deploy berikutnya.')
                        ->authorize(fn () => auth()->user()->canOperate())
                        ->action(fn (Collection $records) => $records->each->update(['is_active' => false]))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }
}
