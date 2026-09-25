<?php

namespace App\Filament\Resources\TaskTemplates\Pages;

use App\Filament\Concerns\ConfirmsSave;
use App\Filament\Resources\TaskTemplates\TaskTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTaskTemplate extends EditRecord
{
    use ConfirmsSave;

    protected static string $resource = TaskTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
