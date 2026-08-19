<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Profile')
                ->description('Identity shown in the admin panel and audit history.')
                ->columns(3)
                ->schema([
                    FileUpload::make('avatar_path')
                        ->label('Avatar')
                        ->disk('public')
                        ->directory('avatars')
                        ->visibility('public')
                        ->image()
                        ->avatar()
                        ->imageEditor()
                        ->maxSize(2048)
                        ->columnSpan(1),

                    TextInput::make('name')
                        ->required()
                        ->maxLength(120)
                        ->columnSpan(2),

                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(191)
                        ->unique(ignoreRecord: true)
                        ->columnSpan(2),

                    Select::make('role')
                        ->options(collect(UserRole::cases())->mapWithKeys(fn (UserRole $role) => [$role->value => $role->label()]))
                        ->default(UserRole::Viewer->value)
                        ->required(),

                    Toggle::make('is_active')
                        ->label('Can sign in')
                        ->default(true),
                ]),

            Section::make('Security')
                ->description('Leave both password fields empty while editing to keep the current password.')
                ->columns(2)
                ->schema([
                    TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->autocomplete('new-password')
                        ->minLength(8)
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->same('password_confirmation')
                        ->dehydrated(fn (?string $state): bool => filled($state)),

                    TextInput::make('password_confirmation')
                        ->label('Confirm password')
                        ->password()
                        ->revealable()
                        ->autocomplete('new-password')
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated(false),
                ]),
        ]);
    }
}
