<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Enums\RunStatus;
use App\Filament\Resources\Clients\Actions\AssignJobsAction;
use App\Filament\Resources\Schedules\Actions\DeleteScheduleAction;
use App\Filament\Resources\Schedules\Actions\ScheduleBulkActions;
use App\Filament\Resources\Schedules\Actions\ToggleScheduleAction;
use App\Filament\Resources\Schedules\ScheduleResource;
use App\Models\Schedule;
use App\Services\CronDescriber;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SchedulesRelationManager extends RelationManager
{
    protected static string $relationship = 'schedules';

    protected static ?string $title = 'Schedules';

    protected static string|BackedEnum|null $icon = 'heroicon-o-calendar-days';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->schedules()->count();
    }

    public static function getBadgeTooltip(Model $ownerRecord, string $pageClass): ?string
    {
        return $ownerRecord->schedules()->where('is_enabled', true)->count().' enabled';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['client', 'taskTemplate']))
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('No schedules for this client')
            ->emptyStateDescription('Use Assign jobs to create paused schedules from the job templates.')
            ->columns([
                TextColumn::make('taskTemplate.key')->label('Job')->searchable()->sortable()->weight('bold'),
                TextColumn::make('cron_expression')->label('Cron')->fontFamily('mono')
                    ->description(fn (Schedule $record) => app(CronDescriber::class)->describe($record->cron_expression)),
                TextColumn::make('timezone')->color('gray')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('next_run_at')->label('Next run')->dateTime('d M H:i')
                    ->timezone(config('opsifin_cron.default_timezone'))->placeholder('Paused')->sortable(),
                TextColumn::make('latest_result')->label('Latest')
                    ->state(fn (Schedule $record) => $record->runs()->latest('scheduled_for')->value('status'))
                    ->badge()
                    ->formatStateUsing(fn (RunStatus|string|null $state) => self::runStatus($state)?->label() ?? 'No runs')
                    ->color(fn (RunStatus|string|null $state) => self::runStatus($state)?->color() ?? 'gray'),
                IconColumn::make('is_enabled')->label('Enabled')->boolean()->action(ToggleScheduleAction::make()),
            ])
            ->headerActions([
                AssignJobsAction::forOwner(),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('edit')->label('Edit')->icon('heroicon-o-pencil-square')
                        ->url(fn (Schedule $record) => ScheduleResource::getUrl('edit', ['record' => $record])),
                    DeleteScheduleAction::make(),
                ])->label('Actions')->tooltip('Actions')->color('gray'),
            ])
            ->toolbarActions([
                BulkActionGroup::make(ScheduleBulkActions::all()),
            ]);
    }

    private static function runStatus(RunStatus|string|null $state): ?RunStatus
    {
        return $state instanceof RunStatus ? $state : RunStatus::tryFrom((string) $state);
    }
}
