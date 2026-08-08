<?php

namespace App\Filament\Resources\Schedules\Pages;

use App\Filament\Resources\Schedules\ScheduleResource;
use App\Models\Client;
use App\Models\TaskTemplate;
use Filament\Resources\Pages\CreateRecord;

class CreateSchedule extends CreateRecord
{
    protected static string $resource = ScheduleResource::class;

    /**
     * Matrix mengirim client dan task lewat query string, supaya klik pada sel
     * kosong langsung membuka form dengan kombinasi itu sudah terisi.
     *
     * Diisi setelah parent::fillForm() dan lewat fillPartially(), bukan
     * fill([...]) — kalau state diberikan sekaligus, default milik field lain
     * (timezone, lock mode, timeout) tidak ikut terpasang.
     */
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
            'lock_key' => $client->code.'.'.$task->key,
        ], ['client_id', 'task_template_id', 'lock_key']);
    }
}
