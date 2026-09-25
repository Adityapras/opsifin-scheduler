<?php

namespace App\Filament\Resources\Clients\Actions;

use App\Models\Client;
use App\Services\Scheduling\DefaultScheduleProvisioner;
use Closure;
use Cron\CronExpression;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use InvalidArgumentException;

class AssignJobsAction
{
    /** Aksi baris tabel Clients. */
    public static function forRecord(): Action
    {
        return self::build(fromOwner: false);
    }

    /** Aksi header tab Schedules di halaman Edit Client. */
    public static function forOwner(): Action
    {
        return self::build(fromOwner: true);
    }

    private static function build(bool $fromOwner): Action
    {
        $client = fn (mixed $livewire, ?Client $record): Client => $fromOwner ? $livewire->getOwnerRecord() : $record;

        return Action::make('assignJobs')
            ->label('Assign jobs')
            ->icon('heroicon-o-squares-plus')
            ->color('primary')
            ->authorize(fn (): bool => auth()->user()->canManage())
            ->modalHeading(fn ($livewire, ?Client $record = null): string => 'Assign jobs to '.$client($livewire, $record)->code)
            ->modalDescription(fn ($livewire, DefaultScheduleProvisioner $provisioner, ?Client $record = null): string => $provisioner
                ->unassignedTasks($client($livewire, $record))->isEmpty()
                    ? 'Every active job is already assigned to this client. Use New schedule to add another timing for an existing job.'
                    : 'Schedules are created PAUSED. Review the request, then resume them from the Schedules tab.')
            ->modalSubmitActionLabel('Assign')
            ->modalSubmitAction(fn ($livewire, DefaultScheduleProvisioner $provisioner, ?Client $record = null) => $provisioner
                ->unassignedTasks($client($livewire, $record))->isEmpty() ? false : null)
            ->schema(fn ($livewire, DefaultScheduleProvisioner $provisioner, ?Client $record = null): array => self::schema(
                $client($livewire, $record), $provisioner,
            ))
            ->action(function (array $data, $livewire, DefaultScheduleProvisioner $provisioner, ?Client $record = null) use ($client): void {
                $target = $client($livewire, $record);
                $custom = ($data['timing'] ?? 'default') === 'custom';

                try {
                    $result = $provisioner->assign(
                        $target,
                        $data['task_ids'] ?? [],
                        $custom ? $data['cron_expression'] : null,
                        $custom ? $data['timezone'] : null,
                    );
                } catch (InvalidArgumentException $exception) {
                    Notification::make()->title('No job was assigned')->body($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title($result['created'].' job(s) assigned to '.$target->code)
                    ->body('Created paused.'.($result['skipped'] > 0 ? ' '.$result['skipped'].' skipped because they were already assigned or inactive.' : ''))
                    ->success()
                    ->send();
            });
    }

    /** @return array<int, mixed> */
    private static function schema(Client $client, DefaultScheduleProvisioner $provisioner): array
    {
        $tasks = $provisioner->unassignedTasks($client);

        if ($tasks->isEmpty()) {
            return [];
        }

        return [
            CheckboxList::make('task_ids')
                ->label('Jobs not assigned yet ('.$tasks->count().')')
                ->options($tasks->mapWithKeys(fn ($task) => [$task->id => $task->key.' — '.$task->name]))
                ->descriptions($tasks->mapWithKeys(fn ($task) => [
                    $task->id => ($task->config['method'] ?? 'POST').' '.($task->config['path'] ?? '').' · default '.$task->default_cron_expression,
                ]))
                ->bulkToggleable()
                ->searchable($tasks->count() > 8)
                ->required(),
            Radio::make('timing')
                ->options([
                    'default' => "Use each job's default cron",
                    'custom' => 'Use the same cron for every selected job',
                ])
                ->default('default')
                ->live(),
            TextInput::make('cron_expression')
                ->label('Cron expression')
                ->placeholder('*/5 * * * *')
                ->visible(fn (Get $get): bool => $get('timing') === 'custom')
                ->required(fn (Get $get): bool => $get('timing') === 'custom')
                ->rule(fn () => function (string $attribute, mixed $value, Closure $fail): void {
                    if (! CronExpression::isValidExpression((string) $value)) {
                        $fail('The cron expression is not valid.');
                    }
                }),
            Select::make('timezone')
                ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                ->searchable()
                ->default($client->timezone ?: config('opsifin_cron.default_timezone'))
                ->visible(fn (Get $get): bool => $get('timing') === 'custom')
                ->required(fn (Get $get): bool => $get('timing') === 'custom'),
        ];
    }
}
