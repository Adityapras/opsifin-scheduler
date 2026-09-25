<?php

namespace App\Filament\Resources\Schedules\Actions;

use App\Models\Schedule;
use App\Services\Scheduling\ScheduleManager;
use Filament\Actions\Action;

class ToggleScheduleAction
{
    /** Dipasang pada IconColumn `is_enabled`; Resume membuat HTTP nyata, jadi selalu dikonfirmasi. */
    public static function make(): Action
    {
        return Action::make('toggleEnabled')
            ->authorize(fn (Schedule $record) => auth()->user()->can('toggle', $record))
            ->requiresConfirmation()
            ->modalHeading(fn (Schedule $record) => ($record->is_enabled ? 'Pause' : 'Resume').' '.$record->client->code.' / '.$record->taskTemplate->key.'?')
            ->modalDescription(fn (Schedule $record) => $record->is_enabled
                ? 'No new occurrence is created until the schedule is resumed.'
                : 'The schedule starts sending HTTP requests to the client on its next occurrence.')
            ->modalSubmitActionLabel(fn (Schedule $record) => $record->is_enabled ? 'Pause' : 'Resume')
            ->action(fn (Schedule $record, ScheduleManager $manager) => $manager->setEnabled($record, ! $record->is_enabled));
    }
}
