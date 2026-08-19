<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Enums\AuthType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identity')
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->label('Code')
                            ->helperText('Stable identifier used in filters and audit history. Letters, digits, dots and dashes only.')
                            ->required()
                            ->maxLength(64)
                            ->rule('regex:/^[A-Za-z0-9._-]+$/')
                            ->unique(ignoreRecord: true),

                        TextInput::make('name')
                            ->label('Name')
                            ->required(),

                        TextInput::make('base_url')
                            ->label('Base URL')
                            ->url()
                            ->required()
                            ->helperText('No trailing slash. The path comes from the task template.')
                            ->columnSpanFull(),

                        Select::make('timezone')
                            ->label('Timezone')
                            ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                            ->searchable()
                            ->required()
                            ->default(config('opsifin_cron.default_timezone')),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->helperText('Pausing a client prevents every schedule for it from executing.')
                            ->default(true),
                    ]),

                Section::make('Credentials')
                    ->description('Stored as entered in the database and redacted from run output, logs, previews, and notifications. This form is restricted to administrators.')
                    ->columns(2)
                    ->schema([
                        Select::make('auth_type')
                            ->label('Auth type')
                            ->options(collect(AuthType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                            ->default(AuthType::Basic->value)
                            ->live()
                            ->required(),

                        TextInput::make('auth_username')
                            ->label('Username')
                            ->visible(fn (Get $get) => $get('auth_type') === AuthType::Basic->value)
                            ->required(fn (Get $get) => $get('auth_type') === AuthType::Basic->value),

                        TextInput::make('auth_secret')
                            ->label(fn (Get $get) => $get('auth_type') === AuthType::Bearer->value ? 'Token' : 'Password')
                            ->visible(fn (Get $get) => $get('auth_type') !== AuthType::None->value)
                            ->password()
                            ->revealable()
                            ->autocomplete('off')
                            ->helperText('Stored as entered. Use the eye button to show or hide the current value.')
                            ->columnSpanFull(),

                        TextInput::make('auth_secret_key')
                            ->label('Secret key')
                            ->helperText('Value for the SecretKey header. Stored as entered; use the eye button to show or hide it.')
                            ->password()
                            ->revealable()
                            ->autocomplete('off')
                            ->columnSpanFull(),
                    ]),

                Section::make('Review & notes')
                    ->columns(2)
                    ->schema([
                        Toggle::make('needs_review')
                            ->label('Needs manual verification')
                            ->helperText('Set automatically by the importer when credentials drift or base URLs conflict.'),

                        Textarea::make('review_notes')
                            ->label('Review notes')
                            ->rows(3)
                            ->columnSpanFull(),

                        Textarea::make('notes')
                            ->label('Free-form notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                Section::make('Legacy origin')
                    ->description('Filled in by the importer. For migration tracing only.')
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        TextInput::make('legacy_config_file')
                            ->label('Config file')
                            ->disabled(),

                        TextInput::make('legacy_script_dir')
                            ->label('Script folder')
                            ->disabled(),
                    ]),
            ]);
    }
}
