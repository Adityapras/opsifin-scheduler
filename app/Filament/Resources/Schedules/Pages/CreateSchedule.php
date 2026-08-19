<?php

namespace App\Filament\Resources\Schedules\Pages;

use App\Filament\Resources\Schedules\ScheduleResource;
use App\Models\Client;
use App\Models\TaskTemplate;
use Filament\Resources\Pages\CreateRecord;

class CreateSchedule extends CreateRecord
{
    protected static string $resource = ScheduleResource::class;

    /** Allow deep links to prefill an assignment without losing field defaults. */
    protected function fillForm(): void
    {
        parent::fillForm();

        $client = Client::find(request()->integer('client_id'));
        $task = TaskTemplate::find(request()->integer('task_template_id'));

        if ($client === null || $task === null) {
            return;
        }

        $this->form->fillPartially([
            'client_id' => $client->getKey(),
            'task_template_id' => $task->getKey(),
        ], ['client_id', 'task_template_id']);
    }
}
