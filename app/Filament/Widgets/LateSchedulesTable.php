<?php

namespace App\Filament\Widgets;

use App\Enums\RunStatus;
use App\Filament\Resources\Runs\RunResource;
use App\Models\Run;
use App\Services\Scheduling\QueuedRunCanceller;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use InvalidArgumentException;

class LateSchedulesTable extends TableWidget
{
    protected static ?int $sort = 2;

    protected ?string $pollingInterval = '30s';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->is_active ?? false;
    }

    public function getTableHeading(): string
    {
        return 'Oldest pending occurrences';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Run::query()->with(['client', 'taskTemplate'])->where('status', RunStatus::Queued->value))
            ->defaultSort('queued_at')->paginationPageOptions([5, 10, 25])
            ->emptyStateHeading('Queue is clear')
            ->emptyStateDescription('No occurrence is waiting for a worker.')
            ->columns([
                TextColumn::make('queued_at')->label('Waiting')->since()->color('warning')->sortable(),
                TextColumn::make('client.code')->label('Client')->weight('bold')->placeholder('Deleted'),
                TextColumn::make('taskTemplate.key')->label('Task')->placeholder('Deleted'),
                TextColumn::make('scheduled_for')->label('Scheduled for')->dateTime('d M H:i:s')->timezone(config('opsifin_cron.default_timezone')),
                TextColumn::make('trigger')->badge()->color('gray'),
            ])
            ->recordActions([
                Action::make('cancel')->icon('heroicon-o-x-circle')->color('danger')
                    ->requiresConfirmation()
                    ->authorize(fn (Run $record) => auth()->user()->can('cancel', $record))
                    ->action(function (Run $record, QueuedRunCanceller $canceller): void {
                        try {
                            $canceller->cancel($record);
                            Notification::make()->title('Run #'.$record->id.' cancelled')->success()->send();
                        } catch (InvalidArgumentException) {
                            Notification::make()->title('Run #'.$record->id.' is no longer queued')->warning()->send();
                        }
                    }),
                Action::make('open')->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Run $record) => RunResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
