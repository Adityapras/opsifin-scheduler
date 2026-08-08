<?php

namespace App\Filament\Resources\Alerts\Pages;

use App\Filament\Resources\Alerts\AlertResource;
use Filament\Resources\Pages\ListRecords;

class ListAlerts extends ListRecords
{
    protected static string $resource = AlertResource::class;

    /** Alert lahir dari rule, tidak pernah dibuat manual. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
