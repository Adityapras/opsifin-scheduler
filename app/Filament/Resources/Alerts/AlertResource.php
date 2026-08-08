<?php

namespace App\Filament\Resources\Alerts;

use App\Enums\AlertStatus;
use App\Filament\Resources\Alerts\Pages\ListAlerts;
use App\Filament\Resources\Alerts\Pages\ViewAlert;
use App\Filament\Resources\Alerts\Schemas\AlertInfolist;
use App\Filament\Resources\Alerts\Tables\AlertsTable;
use App\Models\Alert;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AlertResource extends Resource
{
    protected static ?string $model = Alert::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?string $navigationLabel = 'Alerts';

    protected static ?string $modelLabel = 'alert';

    protected static ?string $pluralModelLabel = 'alerts';

    protected static ?int $navigationSort = 25;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    public static function infolist(Schema $schema): Schema
    {
        return AlertInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AlertsTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        $open = Alert::where('status', AlertStatus::Open->value)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Alerts that nobody has picked up yet';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAlerts::route('/'),
            'view' => ViewAlert::route('/{record}'),
        ];
    }
}
