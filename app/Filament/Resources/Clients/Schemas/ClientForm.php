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
                Section::make('Identitas')
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->label('Kode')
                            ->helperText('Dipakai sebagai bagian dari lock key dan nama di crontab. Huruf, angka, titik, dan strip saja.')
                            ->required()
                            ->maxLength(64)
                            ->rule('regex:/^[A-Za-z0-9._-]+$/')
                            ->unique(ignoreRecord: true),

                        TextInput::make('name')
                            ->label('Nama')
                            ->required(),

                        TextInput::make('base_url')
                            ->label('Base URL')
                            ->url()
                            ->required()
                            ->helperText('Tanpa trailing slash. Path diambil dari task template.')
                            ->columnSpanFull(),

                        Select::make('timezone')
                            ->label('Timezone')
                            ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                            ->searchable()
                            ->required()
                            ->default(config('opsifin_cron.default_timezone')),

                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->helperText('Client nonaktif tidak ikut di-render ke crontab.')
                            ->default(true),
                    ]),

                Section::make('Kredensial')
                    ->description('Disimpan terenkripsi (AES-256). Tidak pernah ditulis ke file atau ke crontab.')
                    ->columns(2)
                    ->schema([
                        Select::make('auth_type')
                            ->label('Tipe auth')
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
                            ->password()
                            ->revealable()
                            ->visible(fn (Get $get) => $get('auth_type') !== AuthType::None->value)
                            ->columnSpanFull(),

                        TextInput::make('auth_secret_key')
                            ->label('Secret key')
                            ->helperText('Nilai untuk header SecretKey (dipakai task remittance/BCA). Kosongkan bila tidak dipakai.')
                            ->password()
                            ->revealable()
                            ->columnSpanFull(),
                    ]),

                Section::make('Review & catatan')
                    ->columns(2)
                    ->schema([
                        Toggle::make('needs_review')
                            ->label('Butuh verifikasi manual')
                            ->helperText('Diset otomatis oleh importer bila ada drift kredensial atau konflik base URL.'),

                        Textarea::make('review_notes')
                            ->label('Catatan review')
                            ->rows(3)
                            ->columnSpanFull(),

                        Textarea::make('notes')
                            ->label('Catatan bebas')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                Section::make('Asal data legacy')
                    ->description('Diisi importer. Hanya untuk penelusuran saat migrasi.')
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        TextInput::make('legacy_config_file')
                            ->label('File config')
                            ->disabled(),

                        TextInput::make('legacy_script_dir')
                            ->label('Folder script')
                            ->disabled(),
                    ]),
            ]);
    }
}
