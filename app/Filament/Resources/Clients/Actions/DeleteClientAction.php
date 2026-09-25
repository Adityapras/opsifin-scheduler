<?php

namespace App\Filament\Resources\Clients\Actions;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Schedules\ScheduleResource;
use App\Models\Client;
use App\Services\Maintenance\ClientDeleter;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use InvalidArgumentException;

class DeleteClientAction
{
    /**
     * Tombol tetap terlihat walau Client masih punya Schedule, supaya operator
     * mendapat penjelasan dan jalan pintas ke daftar Schedule-nya alih-alih
     * tombol yang hilang tanpa alasan.
     */
    public static function make(bool $redirectToIndex = false): Action
    {
        return Action::make('deleteClient')
            ->label('Delete')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->authorize(fn (): bool => auth()->user()->canManage())
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-trash')
            ->modalHeading(fn (Client $record): string => 'Delete client '.$record->code.'?')
            ->modalDescription(function (Client $record): string {
                $count = $record->schedules()->count();

                return $count > 0
                    ? 'This client still has '.$count.' schedule(s). Delete every schedule of this client first, then delete the client.'
                    : 'The client is removed permanently. Run history is kept and the deletion is written to Audit history.';
            })
            ->modalSubmitActionLabel('Delete')
            ->modalSubmitAction(fn (Client $record) => $record->schedules()->exists() ? false : null)
            ->extraModalFooterActions(fn (Client $record): array => $record->schedules()->exists() ? [
                Action::make('openSchedules')
                    ->label('Open schedules of this client')
                    ->icon('heroicon-o-calendar-days')
                    ->url(ScheduleResource::getUrl('index', [
                        'filters' => ['client_id' => ['values' => [$record->id]]],
                    ])),
            ] : [])
            ->action(function (Client $record, ClientDeleter $deleter) use ($redirectToIndex) {
                try {
                    $deleter->delete($record);
                } catch (InvalidArgumentException $exception) {
                    Notification::make()->title($record->code.' was not deleted')->body($exception->getMessage())->warning()->send();

                    return null;
                }

                Notification::make()->title('Client '.$record->code.' deleted')->success()->send();

                return $redirectToIndex ? redirect(ClientResource::getUrl('index')) : null;
            });
    }
}
