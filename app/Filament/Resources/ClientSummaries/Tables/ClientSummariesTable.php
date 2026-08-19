<?php

namespace App\Filament\Resources\ClientSummaries\Tables;

use App\Models\Client;
use App\Services\Scheduling\ClientScheduleSummary;
use Filament\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ClientSummariesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('schedules.taskTemplate'))
            ->defaultSort('code')
            ->persistFiltersInSession()
            ->columns([
                TextColumn::make('code')
                    ->label('Client')
                    ->description(fn (Client $record): string => $record->name)
                    ->searchable(['code', 'name'])
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('coverage')
                    ->label('Active job coverage')
                    ->state(function (Client $record): string {
                        $summary = app(ClientScheduleSummary::class);

                        return $summary->assignedActiveTaskCount($record).' / '.$summary->activeTaskCount();
                    })
                    ->badge()
                    ->color(fn (Client $record): string => app(ClientScheduleSummary::class)->isComplete($record) ? 'success' : 'warning'),

                TextColumn::make('configured_jobs')
                    ->label('Jobs in use')
                    ->state(fn (Client $record): array => app(ClientScheduleSummary::class)->configuredJobKeys($record)->all())
                    ->badge()
                    ->color('primary')
                    ->listWithLineBreaks()
                    ->limitList(4)
                    ->expandableLimitedList()
                    ->placeholder('No jobs'),

                TextColumn::make('missing_jobs')
                    ->label('Missing active jobs')
                    ->state(fn (Client $record): array => app(ClientScheduleSummary::class)->missingJobKeys($record)->all())
                    ->badge()
                    ->color('danger')
                    ->listWithLineBreaks()
                    ->limitList(4)
                    ->expandableLimitedList()
                    ->placeholder('Complete'),

                TextColumn::make('schedules_count')
                    ->label('Timings')
                    ->counts('schedules')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('enabled_schedules')
                    ->label('Enabled')
                    ->state(fn (Client $record): int => $record->schedules->where('is_enabled', true)->count())
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray')
                    ->alignEnd(),

                IconColumn::make('needs_review')
                    ->label('Review')
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('warning')
                    ->falseIcon('heroicon-o-check-circle')
                    ->falseColor('success'),

                IconColumn::make('is_active')
                    ->label('Client active')
                    ->boolean(),
            ])
            ->filters([
                Filter::make('incomplete')
                    ->label('Missing job assignments')
                    ->query(fn ($query) => ClientScheduleSummary::applyIncompleteFilter($query)),
                TernaryFilter::make('is_active')->label('Client active'),
                TernaryFilter::make('needs_review')->label('Needs review'),
            ])
            ->recordActions([
                Action::make('details')
                    ->label('Schedule details')
                    ->icon('heroicon-o-list-bullet')
                    ->color('primary')
                    ->modalHeading(fn (Client $record): string => $record->code.' job coverage')
                    ->modalDescription('All assigned timings and active task templates that are still missing.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('5xl')
                    ->modalContent(fn (Client $record) => view('filament.resources.client-summaries.schedule-details', [
                        'client' => $record->loadMissing('schedules.taskTemplate'),
                        'missingJobs' => app(ClientScheduleSummary::class)->missingJobKeys($record),
                    ])),
            ])
            ->emptyStateHeading('No clients found')
            ->emptyStateDescription('Add a client before reviewing job coverage.')
            ->emptyStateIcon('heroicon-o-chart-bar-square');
    }
}
