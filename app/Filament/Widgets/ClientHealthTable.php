<?php

namespace App\Filament\Widgets;

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\Client;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Success rate per client selama 24 jam terakhir, plus dua angka yang biasanya
 * jadi penyebabnya: lock contention dan durasi rata-rata.
 */
class ClientHealthTable extends TableWidget
{
    protected static ?int $sort = 3;

    protected ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->is_active ?? false;
    }

    public function getTableHeading(): string
    {
        return 'Client health, last 24 hours';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->query())
            ->defaultSort('failed_runs', 'desc')
            ->paginationPageOptions([10, 25, 50])
            ->emptyStateHeading('No runs in the last 24 hours')
            ->columns([
                TextColumn::make('code')->label('Client')->weight('bold')->searchable(),

                TextColumn::make('total_runs')
                    ->label('Runs')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('success_rate')
                    ->label('Success rate')
                    ->alignEnd()
                    ->badge()
                    ->state(fn ($record) => $record->total_runs > 0
                        ? round($record->success_runs / $record->total_runs * 100).'%'
                        : '—')
                    ->color(fn ($record) => match (true) {
                        $record->total_runs == 0 => 'gray',
                        $record->success_runs / $record->total_runs >= 0.99 => 'success',
                        $record->success_runs / $record->total_runs >= 0.9 => 'warning',
                        default => 'danger',
                    }),

                TextColumn::make('failed_runs')
                    ->label('Failed')
                    ->alignEnd()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray')
                    ->sortable(),

                TextColumn::make('locked_runs')
                    ->label('Lock skips')
                    ->alignEnd()
                    ->tooltip('Runs skipped because the previous execution was still going')
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray')
                    ->sortable(),

                TextColumn::make('avg_duration')
                    ->label('Avg duration')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format((float) $state).' ms')
                    ->sortable(),
            ]);
    }

    /**
     * @return Builder<Client>
     */
    private function query(): Builder
    {
        $since = now()->subDay();
        $real = fn ($q) => $q->where('started_at', '>=', $since)
            ->whereNot('trigger', RunTrigger::DryRun->value);

        return Client::query()
            ->select('clients.*')
            ->withCount([
                'runs as total_runs' => $real,
                'runs as success_runs' => fn ($q) => $real($q)->where('status', RunStatus::Success->value),
                'runs as failed_runs' => fn ($q) => $real($q)->whereIn('status', [
                    RunStatus::Failed->value,
                    RunStatus::Timeout->value,
                ]),
                'runs as locked_runs' => fn ($q) => $real($q)->where('status', RunStatus::SkippedLock->value),
            ])
            ->withAvg(['runs as avg_duration' => $real], 'duration_ms')
            ->having('total_runs', '>', 0);
    }
}
