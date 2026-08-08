<?php

namespace App\Filament\Resources\Runs\Pages;

use App\Filament\Resources\Runs\RunResource;
use Filament\Resources\Pages\ListRecords;

class ListRuns extends ListRecords
{
    protected static string $resource = RunResource::class;

    /** Riwayat eksekusi tidak bisa dibuat manual — hanya runner yang menulis tabel runs. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
