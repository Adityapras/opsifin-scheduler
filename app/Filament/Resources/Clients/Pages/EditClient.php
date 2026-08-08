<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    /**
     * `$hidden` di model Client membuang auth_secret dan auth_secret_key dari
     * attributesToArray(), padahal itulah yang dipakai Filament untuk mengisi
     * form — akibatnya kedua field datang kosong dan menyimpan form akan
     * menimpa kredensial tersimpan dengan nilai kosong.
     *
     * Diambil eksplisit di sini, bukan dengan mencabut $hidden, supaya kolomnya
     * tetap tidak ikut terbawa pada serialisasi lain (log, toArray, JSON).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        $data['auth_secret'] = $record->auth_secret;
        $data['auth_secret_key'] = $record->auth_secret_key;

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
