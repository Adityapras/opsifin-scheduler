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
                            ->helperText('Used as part of the lock key and the name in the crontab. Letters, digits, dots and dashes only.')
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
                            ->helperText('Inactive clients are not rendered into the crontab.')
                            ->default(true),
                    ]),

                Section::make('Credentials')
                    ->description('Stored encrypted (AES-256) and never written to a file or into the crontab, but shown in full here — the values have to be readable to be reconciled against the legacy scripts.')
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
                            ->autocomplete(false)
                            ->columnSpanFull(),

                        TextInput::make('auth_secret_key')
                            ->label('Secret key')
                            ->helperText('Value for the SecretKey header (used by the remittance/BCA tasks). Leave empty if unused.')
                            ->autocomplete(false)
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
