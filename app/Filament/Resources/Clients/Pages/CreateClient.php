<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Services\Scheduling\DefaultScheduleProvisioner;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateClient extends CreateRecord
{
    protected static string $resource = ClientResource::class;

    protected int $provisionedScheduleCount = 0;

    protected function afterCreate(): void
    {
        if (($this->data['provision_default_schedules'] ?? true) === false) {
            return;
        }

        $this->provisionedScheduleCount = app(DefaultScheduleProvisioner::class)
            ->provision($this->getRecord());
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Client created')
            ->body($this->provisionedScheduleCount.' default schedule(s) created in paused state.');
    }
}
