<?php

namespace App\Filament\Resources\Schedules\Actions;

use App\Services\Scheduling\ScheduleManager;
use Closure;
use Cron\CronExpression;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

/** Bulk action Schedule yang dipakai bersama tabel Schedules dan tab Schedules di Edit Client. */
class ScheduleBulkActions
{
    /** @return array<int, BulkAction> */
    public static function all(): array
    {
        return [self::setCron(), self::resume(), self::pause(), DeleteScheduleAction::bulk()];
    }

    public static function setCron(): BulkAction
    {
        return BulkAction::make('setCron')->label('Set cron in bulk')->icon('heroicon-o-clock')
            ->authorize(fn () => auth()->user()->canManage())
            ->schema([
                TextInput::make('cron_expression')->required()->default('*/5 * * * *')
                    ->rule(fn () => function (string $attribute, mixed $value, Closure $fail): void {
                        if (! CronExpression::isValidExpression((string) $value)) {
                            $fail('The cron expression is not valid.');
                        }
                    }),
                Select::make('timezone')->label('Timezone')
                    ->placeholder('Keep each existing timezone')
                    ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                    ->searchable(),
            ])
            ->requiresConfirmation()
            ->action(function (Collection $records, array $data, ScheduleManager $manager): void {
                $manager->changeTimingBulk($records, $data['cron_expression'], $data['timezone'] ?? null);
                Notification::make()->title($records->count().' schedule(s) updated')->success()->send();
            })->deselectRecordsAfterCompletion();
    }

    public static function resume(): BulkAction
    {
        return BulkAction::make('resume')->label('Resume selected')->icon('heroicon-o-play')->color('success')
            ->requiresConfirmation()->authorize(fn () => auth()->user()->canOperate())
            ->action(fn (Collection $records, ScheduleManager $manager) => $manager->setEnabledBulk($records, true))
            ->deselectRecordsAfterCompletion();
    }

    public static function pause(): BulkAction
    {
        return BulkAction::make('pause')->label('Pause selected')->icon('heroicon-o-pause')->color('danger')
            ->requiresConfirmation()->authorize(fn () => auth()->user()->canOperate())
            ->action(fn (Collection $records, ScheduleManager $manager) => $manager->setEnabledBulk($records, false))
            ->deselectRecordsAfterCompletion();
    }
}
