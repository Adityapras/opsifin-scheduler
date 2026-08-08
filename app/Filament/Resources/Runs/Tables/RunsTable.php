<?php

namespace App\Filament\Resources\Runs\Tables;

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\Run;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['client', 'taskTemplate']))
            ->defaultSort('started_at', 'desc')
            ->poll('30s')
            ->persistFiltersInSession()
            ->columns([
                TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime('d M H:i:s')
                    ->timezone(config('opsifin_cron.default_timezone'))
                    ->sortable(),

                TextColumn::make('client.code')
                    ->label('Client')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('taskTemplate.key')
                    ->label('Task')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (RunStatus $state) => $state->label())
                    ->color(fn (RunStatus $state) => match ($state) {
                        RunStatus::Success => 'success',
                        RunStatus::Failed, RunStatus::Timeout => 'danger',
                        RunStatus::SkippedLock => 'warning',
                        RunStatus::Running => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('http_status')
                    ->label('HTTP')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (?int $state) => $state === null ? 'gray' : ($state < 400 ? 'success' : 'danger')),

                TextColumn::make('duration_ms')
                    ->label('Duration')
                    ->alignEnd()
                    ->formatStateUsing(fn (?int $state) => $state === null ? '—' : number_format($state).' ms')
                    ->sortable(),

                TextColumn::make('trigger')
                    ->label('Trigger')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (RunTrigger $state) => $state->label())
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('error_message')
                    ->label('Message')
                    ->limit(50)
                    ->tooltip(fn (Run $record) => $record->error_message)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('client_id')
                    ->label('Client')
                    ->relationship('client', 'code')
                    ->searchable()
                    ->preload()
                    ->multiple(),

                SelectFilter::make('task_template_id')
                    ->label('Task')
                    ->relationship('taskTemplate', 'key')
                    ->searchable()
                    ->preload()
                    ->multiple(),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(RunStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]))
                    ->multiple(),

                SelectFilter::make('trigger')
                    ->label('Trigger')
                    ->options(collect(RunTrigger::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()]))
                    ->multiple(),

                Filter::make('problems_only')
                    ->label('Problems only')
                    ->query(fn ($query) => $query->whereIn('status', [RunStatus::Failed->value, RunStatus::Timeout->value])),

                Filter::make('period')
                    ->schema([
                        DateTimePicker::make('from')->label('From'),
                        DateTimePicker::make('until')->label('Until'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $v) => $q->where('started_at', '>=', $v))
                        ->when($data['until'] ?? null, fn ($q, $v) => $q->where('started_at', '<=', $v))),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
