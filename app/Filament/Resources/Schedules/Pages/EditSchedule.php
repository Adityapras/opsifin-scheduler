<?php

namespace App\Filament\Resources\Schedules\Pages;

use App\Filament\Concerns\ConfirmsSave;
use App\Filament\Resources\Schedules\Actions\DeleteScheduleAction;
use App\Filament\Resources\Schedules\ScheduleResource;
use Filament\Resources\Pages\EditRecord;

class EditSchedule extends EditRecord
{
    use ConfirmsSave;

    protected static string $resource = ScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteScheduleAction::make(redirectToIndex: true),
        ];
    }
}
