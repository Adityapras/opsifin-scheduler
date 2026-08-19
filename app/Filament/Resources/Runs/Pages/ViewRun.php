<?php

namespace App\Filament\Resources\Runs\Pages;

use App\Enums\RunStatus;
use App\Filament\Resources\Runs\RunResource;
use App\Services\Scheduling\RunDispatcher;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewRun extends ViewRecord
{
    protected static string $resource = RunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('retry')
                ->icon('heroicon-o-arrow-path')->color('warning')->requiresConfirmation()
                ->visible(fn () => $this->record->status === RunStatus::Failed && $this->record->schedule_id !== null)
                ->authorize(fn () => auth()->user()->can('retry', $this->record))
                ->action(function (RunDispatcher $dispatcher): void {
                    $retry = $dispatcher->retry($this->record);
                    Notification::make()->title('Retry occurrence #'.$retry->id.' queued')->success()->send();
                }),
        ];
    }
}
