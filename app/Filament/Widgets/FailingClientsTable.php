<?php

namespace App\Filament\Widgets;

use App\Enums\RunStatus;
use App\Filament\Resources\Runs\RunResource;
use App\Models\Client;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class FailingClientsTable extends TableWidget
{
    protected static ?int $sort = 5;

    protected ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->is_active ?? false;
    }

    public function getTableHeading(): string
    {
        return 'Clients with failures, last 24 hours';
    }

    public function table(Table $table): Table
    {
        $failedSince = fn ($query) => $query->where('status', RunStatus::Failed->value)->where('scheduled_for', '>=', now()->subDay());

        return $table
            ->query(Client::query()
                ->whereHas('runs', $failedSince)
                ->withCount(['runs as failures_24h' => $failedSince])
                ->withMax(['runs as last_failure_at' => $failedSince], 'scheduled_for'))
            ->defaultSort('failures_24h', 'desc')
            ->paginationPageOptions([5, 10, 25])
            ->emptyStateHeading('No failures in the last 24 hours')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->columns([
                TextColumn::make('code')->label('Client')->weight('bold'),
                TextColumn::make('name')->limit(30)->color('gray'),
                TextColumn::make('failures_24h')->label('Failures')->badge()->color('danger')->alignEnd()->sortable(),
                TextColumn::make('last_failure_at')->label('Last failure')->since()->sortable()
                    ->timezone(config('opsifin_cron.default_timezone')),
                TextColumn::make('last_error')->label('Last error')->limit(60)->placeholder('—')
                    ->state(fn (Client $record) => $record->runs()->where('status', RunStatus::Failed->value)->latest('scheduled_for')->value('error_message'))
                    ->tooltip(fn (TextColumn $column): ?string => $column->getState()),
            ])
            ->recordActions([
                Action::make('logs')->label('Logs')->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Client $record) => RunResource::getUrl('index', [
                        'filters' => ['client_id' => ['values' => [$record->id]], 'problems_only' => ['isActive' => true]],
                    ])),
            ]);
    }
}
