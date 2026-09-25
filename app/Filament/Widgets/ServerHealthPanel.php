<?php

namespace App\Filament\Widgets;

use App\Services\Monitoring\ServerHealth;
use Filament\Widgets\Widget;

class ServerHealthPanel extends Widget
{
    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '30s';

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.server-health-panel';

    public static function canView(): bool
    {
        return auth()->user()?->is_active ?? false;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $checks = app(ServerHealth::class)->checks();

        return [
            'checks' => $checks,
            'overall' => ServerHealth::overall($checks),
            'problems' => array_values(array_filter($checks, fn (array $check): bool => in_array($check['status'], [ServerHealth::WARNING, ServerHealth::CRITICAL], true))),
            'checkedAt' => now()->timezone(config('opsifin_cron.default_timezone'))->format('d M H:i:s'),
        ];
    }
}
