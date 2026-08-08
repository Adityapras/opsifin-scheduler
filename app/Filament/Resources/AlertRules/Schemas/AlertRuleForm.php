<?php

namespace App\Filament\Resources\AlertRules\Schemas;

use App\Enums\AlertCondition;
use App\Models\Client;
use App\Models\Schedule;
use App\Models\TaskTemplate;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AlertRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Rule')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(191),

                        Select::make('condition')
                            ->label('Condition')
                            ->options(collect(AlertCondition::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                            ->default(AlertCondition::OnFailure->value)
                            ->required()
                            ->live(),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ]),

                Section::make('Scope')
                    ->description('Leave everything empty to watch every schedule. Filling a field narrows the rule down.')
                    ->columns(3)
                    ->schema([
                        Select::make('client_id')
                            ->label('Client')
                            ->relationship('client', 'code')
                            ->getOptionLabelFromRecordUsing(fn (Client $r) => $r->code.' — '.$r->name)
                            ->searchable()
                            ->preload()
                            ->placeholder('Every client'),

                        Select::make('task_template_id')
                            ->label('Task')
                            ->relationship('taskTemplate', 'key')
                            ->getOptionLabelFromRecordUsing(fn (TaskTemplate $r) => $r->key.' — '.$r->name)
                            ->searchable()
                            ->preload()
                            ->placeholder('Every task'),

                        Select::make('schedule_id')
                            ->label('One specific schedule')
                            ->relationship('schedule', 'id')
                            ->getOptionLabelFromRecordUsing(fn (Schedule $r) => $r->client?->code.' / '.$r->taskTemplate?->key)
                            ->searchable()
                            ->preload()
                            ->placeholder('Not limited to one'),
                    ]),

                Section::make('Thresholds')
                    ->columns(3)
                    ->schema([
                        TextInput::make('threshold')
                            ->label('Consecutive failures')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->default(3)
                            ->required(fn (Get $get) => $get('condition') === AlertCondition::ConsecutiveFailures->value)
                            ->visible(fn (Get $get) => $get('condition') === AlertCondition::ConsecutiveFailures->value)
                            ->helperText('How many failures in a row before this rule fires.'),

                        TextInput::make('grace_minutes')
                            ->label('Grace (minutes)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(1440)
                            ->default(10)
                            ->required(fn (Get $get) => $get('condition') === AlertCondition::MissedRun->value)
                            ->visible(fn (Get $get) => $get('condition') === AlertCondition::MissedRun->value)
                            ->helperText('How late a run may be before it counts as missed.'),

                        TextInput::make('cooldown_minutes')
                            ->label('Cooldown (minutes)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(1440)
                            ->default(60)
                            ->required()
                            ->helperText('Minimum gap between alerts from this rule for the same schedule. 0 = no limit — a job failing every 6 minutes will alert every 6 minutes.'),

                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
