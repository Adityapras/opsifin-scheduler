<?php

namespace App\Filament\Widgets;

use App\Enums\RunTrigger;
use App\Models\TaskTemplate;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Task paling lambat 24 jam terakhir.
 *
 * Yang dicari bukan sekadar rata-rata, tapi jarak antara rata-rata dan
 * maksimum: selisih besar berarti ada eksekusi yang sesekali menggantung, dan
 * itulah kandidat pertama penyebab lock contention.
 */
class SlowestTasksTable extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->is_active ?? false;
    }

    public function getTableHeading(): string
    {
        return 'Slowest tasks, last 24 hours';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->query())
            ->defaultSort('max_duration', 'desc')
            ->paginationPageOptions([5, 10, 25])
            ->emptyStateHeading('No runs in the last 24 hours')
            ->columns([
                TextColumn::make('key')->label('Task')->weight('bold')->searchable(),

                TextColumn::make('run_count')
                    ->label('Runs')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('avg_duration')
                    ->label('Average')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format((float) $state).' ms')
                    ->sortable(),

                TextColumn::make('max_duration')
                    ->label('Slowest')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format((float) $state).' ms')
                    ->color(fn ($state, $record) => $record->avg_duration > 0 && $state > $record->avg_duration * 3 ? 'warning' : 'gray')
                    ->tooltip('Highlighted when the slowest run is more than 3× the average')
                    ->sortable(),

                TextColumn::make('default_timeout_sec')
                    ->label('Timeout')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => $state.'s'),
            ]);
    }

    /**
     * @return Builder<TaskTemplate>
     */
    private function query(): Builder
    {
        $since = now()->subDay();

        return TaskTemplate::query()
            ->select('task_templates.*')
            ->selectSub(
                fn ($q) => $q->from('runs')
                    ->selectRaw('count(*)')
                    ->whereColumn('runs.task_template_id', 'task_templates.id')
                    ->where('runs.started_at', '>=', $since)
                    ->whereNot('runs.trigger', RunTrigger::DryRun->value),
                'run_count',
            )
            ->selectSub(
                fn ($q) => $q->from('runs')
                    ->selectRaw('avg(duration_ms)')
                    ->whereColumn('runs.task_template_id', 'task_templates.id')
                    ->where('runs.started_at', '>=', $since)
                    ->whereNot('runs.trigger', RunTrigger::DryRun->value),
                'avg_duration',
            )
            ->selectSub(
                fn ($q) => $q->from('runs')
                    ->selectRaw('max(duration_ms)')
                    ->whereColumn('runs.task_template_id', 'task_templates.id')
                    ->where('runs.started_at', '>=', $since)
                    ->whereNot('runs.trigger', RunTrigger::DryRun->value),
                'max_duration',
            )
            ->havingRaw('run_count > 0');
    }
}
