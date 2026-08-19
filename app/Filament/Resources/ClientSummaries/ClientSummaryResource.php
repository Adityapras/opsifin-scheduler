<?php

namespace App\Filament\Resources\ClientSummaries;

use App\Filament\Resources\ClientSummaries\Pages\ListClientSummaries;
use App\Filament\Resources\ClientSummaries\Tables\ClientSummariesTable;
use App\Models\Client;
use App\Services\Scheduling\ClientScheduleSummary;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ClientSummaryResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static ?string $navigationLabel = 'Client job summary';

    protected static ?string $modelLabel = 'client summary';

    protected static ?string $pluralModelLabel = 'client summaries';

    protected static ?int $navigationSort = 5;

    protected static string|\UnitEnum|null $navigationGroup = 'Insights';

    public static function table(Table $table): Table
    {
        return ClientSummariesTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = ClientScheduleSummary::incompleteClientCount();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Clients missing one or more active job assignments';
    }

    public static function getPages(): array
    {
        return ['index' => ListClientSummaries::route('/')];
    }
}
