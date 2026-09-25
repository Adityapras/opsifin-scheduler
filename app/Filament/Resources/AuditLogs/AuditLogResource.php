<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
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

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Entry')
                ->columns(['md' => 3])
                ->schema([
                    TextEntry::make('created_at')->label('When')->dateTime('d M Y H:i:s')->timezone(config('opsifin_cron.default_timezone')),
                    TextEntry::make('user.name')->label('Actor')->placeholder('System')
                        ->helperText(fn (AuditLog $record): ?string => $record->user?->email),
                    TextEntry::make('action')->badge()->color(fn (string $state) => self::actionColor($state)),
                    TextEntry::make('entity_type')->label('Entity')->badge()->color('gray')
                        ->formatStateUsing(fn (string $state) => class_basename($state)),
                    TextEntry::make('entity_label')->label('Record')->placeholder('—')
                        ->state(fn (AuditLog $record): ?string => AuditLogPresenter::entityLabel($record))
                        ->helperText(fn (AuditLog $record): string => 'ID '.($record->entity_id ?? '—')),
                    TextEntry::make('ip')->label('IP address')->placeholder('—')->fontFamily('mono'),
                ]),
            Section::make('Changes')
                ->description(fn (AuditLog $record): string => match ($record->action) {
                    'created' => 'Values stored when the record was created.',
                    'deleted' => 'Last known values before the record was deleted.',
                    default => 'Highlighted rows changed in this update.',
                })
                ->schema([
                    ViewEntry::make('changes')->hiddenLabel()
                        ->state(fn (AuditLog $record): array => AuditLogPresenter::changes($record))
                        ->view('filament.resources.audit-logs.changes'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('created_at')->label('When')->dateTime('d M Y H:i:s')->timezone(config('opsifin_cron.default_timezone'))->sortable(),
            TextColumn::make('user.name')->label('Actor')->placeholder('System')->searchable(),
            TextColumn::make('action')->badge()->color(fn (string $state) => self::actionColor($state)),
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
            SelectFilter::make('entity_type')->label('Entity')
                ->options(fn (): array => AuditLog::query()->distinct()->orderBy('entity_type')->pluck('entity_type')
                    ->mapWithKeys(fn (string $type) => [$type => class_basename($type)])->all()),
        ])->recordActions([
            ViewAction::make()->label('Details')->slideOver()->modalWidth('3xl')
                ->modalHeading(fn (AuditLog $record): string => ucfirst($record->action).' '.class_basename($record->entity_type).' #'.$record->entity_id),
        ])->recordAction('view');
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditLogs::route('/')];
    }

    private static function actionColor(string $action): string
    {
        return match ($action) {
            'created' => 'success', 'deleted' => 'danger', default => 'warning'
        };
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
