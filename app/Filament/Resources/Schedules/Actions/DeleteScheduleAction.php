<?php

namespace App\Filament\Resources\Schedules\Actions;

use App\Filament\Resources\Schedules\ScheduleResource;
use App\Models\Schedule;
use App\Services\Maintenance\ScheduleDeleter;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class DeleteScheduleAction
{
    public static function make(bool $redirectToIndex = false): Action
    {
        return Action::make('deleteSchedule')
            ->label('Delete')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->authorize(fn (Schedule $record): bool => auth()->user()->can('delete', $record))
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-trash')
            ->modalHeading(fn (Schedule $record): string => 'Delete schedule '.$record->client?->code.' / '.$record->taskTemplate?->key.'?')
            ->modalDescription(fn (Schedule $record): string => ($record->is_enabled ? 'This schedule is ENABLED and will stop running. ' : '')
                .'The schedule is removed permanently. Run history is kept and the deletion is written to Audit history.')
            ->modalSubmitActionLabel('Delete')
            ->action(function (Schedule $record, ScheduleDeleter $deleter) use ($redirectToIndex) {
                try {
                    $deleter->delete($record);
                } catch (InvalidArgumentException $exception) {
                    Notification::make()->title('Schedule was not deleted')->body($exception->getMessage())->warning()->send();

                    return null;
                }

                Notification::make()->title('Schedule deleted')->success()->send();

                return $redirectToIndex ? redirect(ScheduleResource::getUrl('index')) : null;
            });
    }

    public static function bulk(): BulkAction
    {
        return BulkAction::make('deleteSchedules')
            ->label('Delete selected')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->authorize(fn (): bool => auth()->user()->canManage())
            ->requiresConfirmation()
            ->modalHeading('Delete selected schedules?')
            ->modalDescription(fn (Collection $records): string => 'Selected: '.$records->count().' schedule(s), '
                .$records->where('is_enabled', true)->count().' of them enabled. Schedules with a running occurrence are skipped. '
                .'Run history is kept and every deletion is written to Audit history.')
            ->modalSubmitActionLabel('Delete')
            ->action(function (Collection $records, ScheduleDeleter $deleter): void {
                $result = $deleter->deleteMany($records);

                Notification::make()
                    ->title($result['deleted'].' schedule(s) deleted')
                    ->body($result['skipped'] > 0 ? $result['skipped'].' skipped because an occurrence is still running.' : null)
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
