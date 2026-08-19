<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Audit history';

    protected static ?string $modelLabel = 'audit entry';

    protected static ?int $navigationSort = 50;

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('created_at')->label('When')->dateTime('d M Y H:i:s')->timezone(config('opsifin_cron.default_timezone'))->sortable(),
            TextColumn::make('user.name')->label('Actor')->placeholder('System')->searchable(),
            TextColumn::make('action')->badge()->color(fn (string $state) => match ($state) {
                'created' => 'success', 'deleted' => 'danger', default => 'warning'
            }),
            TextColumn::make('entity_type')->label('Entity')->formatStateUsing(fn (string $state) => class_basename($state))->badge()->color('gray'),
            TextColumn::make('entity_id')->label('ID')->alignEnd(),
            TextColumn::make('before_summary')->label('Before')
                ->state(fn (AuditLog $record): string => self::formatChanges($record->before))
                ->limit(70)
                ->tooltip(fn (AuditLog $record): ?string => self::formatChanges($record->before, pretty: true)),
            TextColumn::make('after_summary')->label('After')
                ->state(fn (AuditLog $record): string => self::formatChanges($record->after))
                ->limit(70)
                ->tooltip(fn (AuditLog $record): ?string => self::formatChanges($record->after, pretty: true)),
            TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('action')->options(['created' => 'Created', 'updated' => 'Updated', 'deleted' => 'Deleted']),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditLogs::route('/')];
    }

    /** @param array<string, mixed>|null $changes */
    private static function formatChanges(?array $changes, bool $pretty = false): ?string
    {
        if ($changes === null || $changes === []) {
            return $pretty ? null : '—';
        }

        return json_encode(
            $changes,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | ($pretty ? JSON_PRETTY_PRINT : 0),
        ) ?: ($pretty ? null : '—');
    }
}
