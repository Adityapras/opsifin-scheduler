<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make('Profile')
                ->description('Identity shown in the admin panel and audit history.')
                ->icon('heroicon-o-user-circle')
                ->columns(['md' => 3])
                ->columnSpan(['lg' => 2])
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
                        ->helperText('PNG or JPG, up to 2 MB.'),

                    Group::make([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(120)
                            ->columnSpanFull(),

                        TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(191)
                            ->unique(ignoreRecord: true)
                            ->columnSpanFull(),

                        Select::make('role')
                            ->options(collect(UserRole::cases())->mapWithKeys(fn (UserRole $role) => [$role->value => $role->label()]))
                            ->default(UserRole::Viewer->value)
                            ->required(),

                        Toggle::make('is_active')
                            ->label('Can sign in')
                            ->helperText('Disabled users cannot log in.')
                            ->default(true)
                            ->inline(false),
                    ])->columns(2)->columnSpan(['md' => 2]),
                ]),

            Section::make('Security')
                ->description('Leave both password fields empty while editing to keep the current password.')
                ->icon('heroicon-o-lock-closed')
                ->columnSpan(['lg' => 1])
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
