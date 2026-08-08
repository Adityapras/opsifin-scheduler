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

    protected static ?string $navigationLabel = 'Runs';

    protected static ?string $modelLabel = 'run';

    protected static ?string $pluralModelLabel = 'runs';

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
        $problems = Run::whereIn('status', [RunStatus::Failed->value, RunStatus::Timeout->value])
            ->where('started_at', '>=', now()->subDay())
            ->count();

        return $problems > 0 ? (string) $problems : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Failed or timed out in the last 24 hours';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRuns::route('/'),
            'view' => ViewRun::route('/{record}'),
        ];
    }
}
