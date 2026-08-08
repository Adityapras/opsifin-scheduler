<?php

namespace App\Filament\Resources\AlertRules;

use App\Filament\Resources\AlertRules\Pages\CreateAlertRule;
use App\Filament\Resources\AlertRules\Pages\EditAlertRule;
use App\Filament\Resources\AlertRules\Pages\ListAlertRules;
use App\Filament\Resources\AlertRules\Schemas\AlertRuleForm;
use App\Filament\Resources\AlertRules\Tables\AlertRulesTable;
use App\Models\AlertRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AlertRuleResource extends Resource
{
    protected static ?string $model = AlertRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?string $navigationLabel = 'Alert rules';

    protected static ?string $modelLabel = 'alert rule';

    protected static ?string $pluralModelLabel = 'alert rules';

    protected static ?int $navigationSort = 30;

    protected static string|\UnitEnum|null $navigationGroup = 'Master data';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return AlertRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AlertRulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAlertRules::route('/'),
            'create' => CreateAlertRule::route('/create'),
            'edit' => EditAlertRule::route('/{record}/edit'),
        ];
    }
}
