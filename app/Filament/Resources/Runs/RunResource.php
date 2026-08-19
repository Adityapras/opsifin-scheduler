<?php

namespace App\Filament\Resources\Runs;

use App\Enums\RunStatus;
use App\Filament\Resources\Runs\Pages\ListRuns;
use App\Filament\Resources\Runs\Pages\ViewRun;
use App\Filament\Resources\Runs\Schemas\RunInfolist;
use App\Filament\Resources\Runs\Tables\RunsTable;
use App\Models\Run;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RunResource extends Resource
{
    protected static ?string $model = Run::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?string $navigationLabel = 'Execution logs';

    protected static ?string $modelLabel = 'execution log';

    protected static ?string $pluralModelLabel = 'execution logs';

    protected static ?int $navigationSort = 20;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    public static function infolist(Schema $schema): Schema
    {
        return RunInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RunsTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        $problems = Run::where('status', RunStatus::Failed->value)
            ->where('scheduled_for', '>=', now()->subDay())
            ->count();

        return $problems > 0 ? (string) $problems : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Failed runs in the last 24 hours';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRuns::route('/'),
            'view' => ViewRun::route('/{record}'),
        ];
    }
}
