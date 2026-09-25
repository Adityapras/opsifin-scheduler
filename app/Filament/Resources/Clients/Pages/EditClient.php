<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Concerns\ConfirmsSave;
use App\Filament\Resources\Clients\Actions\DeleteClientAction;
use App\Filament\Resources\Clients\ClientResource;
use BackedEnum;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

class EditClient extends EditRecord
{
    use ConfirmsSave;

    protected static string $resource = ClientResource::class;

    /** @param array<string, mixed> $data @return array<string, mixed> */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['auth_secret'] = $this->record->auth_secret;
        $data['auth_secret_key'] = $this->record->auth_secret_key;

        return $data;
    }

    // Form dan tab Schedules dipisah supaya tombol Save tidak tampak berlaku untuk tabel.
    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Client details';
    }

    public function getContentTabIcon(): string|BackedEnum|Htmlable|null
    {
        return 'heroicon-o-building-office-2';
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteClientAction::make(redirectToIndex: true),
        ];
    }
}
