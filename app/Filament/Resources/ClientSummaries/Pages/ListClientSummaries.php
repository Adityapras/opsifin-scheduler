<?php

namespace App\Filament\Resources\ClientSummaries\Pages;

use App\Filament\Resources\ClientSummaries\ClientSummaryResource;
use App\Filament\Widgets\ClientCoverageOverview;
use Filament\Resources\Pages\ListRecords;

class ListClientSummaries extends ListRecords
{
    protected static string $resource = ClientSummaryResource::class;

    public function getSubheading(): ?string
    {
        return 'Compare every client against the active job catalog and inspect all configured timings in one place.';
    }

    protected function getHeaderWidgets(): array
    {
        return [ClientCoverageOverview::class];
    }
}
