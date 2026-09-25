<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Concerns\ConfirmsSave;
use App\Filament\Resources\Clients\Actions\DeleteClientAction;
use App\Filament\Resources\Clients\ClientResource;
use Filament\Resources\Pages\EditRecord;

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

    protected function getHeaderActions(): array
    {
        return [
            DeleteClientAction::make(redirectToIndex: true),
        ];
    }
}
