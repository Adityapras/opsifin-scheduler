<?php

namespace App\Filament\Concerns;

use Filament\Actions\Action;

/**
 * Tombol Save pada halaman Edit meminta konfirmasi sebelum menulis perubahan.
 *
 * Save bawaan adalah tombol submit form; konfirmasi hanya bisa ditampilkan bila
 * tombol diubah menjadi action modal yang memanggil save() setelah disetujui.
 */
trait ConfirmsSave
{
    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->submit(null)
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-pencil-square')
            ->modalHeading('Save changes?')
            ->modalDescription('The changes take effect immediately and are written to Audit history.')
            ->modalSubmitActionLabel('Save')
            ->action(fn () => $this->save());
    }
}
