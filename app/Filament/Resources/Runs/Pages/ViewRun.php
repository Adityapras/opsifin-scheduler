<?php

namespace App\Filament\Resources\Runs\Pages;

use App\Enums\RunStatus;
use App\Filament\Resources\Runs\RunResource;
use App\Services\Maintenance\RunLogDeleter;
use App\Services\Scheduling\RunDispatcher;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use InvalidArgumentException;

class ViewRun extends ViewRecord
{
    protected static string $resource = RunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('retry')
                ->icon('heroicon-o-arrow-path')->color('warning')->requiresConfirmation()
                ->visible(fn () => config('opsifin_cron.execution_driver') === 'queue' && $this->record->execution_driver !== 'direct' && $this->record->status === RunStatus::Failed && $this->record->schedule_id !== null)
                ->authorize(fn () => auth()->user()->can('retry', $this->record))
                ->action(function (RunDispatcher $dispatcher): void {
                    $retry = $dispatcher->retry($this->record);
                    Notification::make()->title('Retry occurrence #'.$retry->id.' queued')->success()->send();
                }),
            Action::make('delete')
                ->label('Delete log')->icon('heroicon-o-trash')->color('danger')->requiresConfirmation()
                ->modalHeading('Delete this execution log?')
                ->modalDescription('The record is removed permanently. The deletion itself is written to Audit history.')
                ->modalSubmitActionLabel('Delete')
                ->visible(fn () => $this->record->status->isTerminal())
                ->authorize(fn () => auth()->user()->can('delete', $this->record))
                ->action(function (RunLogDeleter $deleter) {
                    try {
                        $deleter->delete($this->record);
                    } catch (InvalidArgumentException) {
                        Notification::make()->title('This run is running and cannot be deleted')->warning()->send();

                        return null;
                    }

                    Notification::make()->title('Execution log deleted')->success()->send();

                    return redirect(RunResource::getUrl('index'));
                }),
        ];
    }
}
