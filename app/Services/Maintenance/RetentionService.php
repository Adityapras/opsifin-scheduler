<?php

namespace App\Services\Maintenance;

use App\Enums\RunStatus;
use App\Models\Run;
use Illuminate\Support\Carbon;

class RetentionService
{
    public function count(int $runDays): int
    {
        $runCutoff = Carbon::now()->subDays($runDays);

        return Run::query()
            ->where('scheduled_for', '<', $runCutoff)
            ->whereNotIn('status', [RunStatus::Queued->value, RunStatus::Running->value])
            ->count();
    }

    public function purge(int $runDays, int $chunk = 1000): int
    {
        $runCutoff = Carbon::now()->subDays($runDays);
        $chunk = max(100, $chunk);

        return $this->deleteInChunks(fn () => Run::query()
            ->where('scheduled_for', '<', $runCutoff)
            ->whereNotIn('status', [RunStatus::Queued->value, RunStatus::Running->value])
            ->limit($chunk)
            ->delete());
    }

    private function deleteInChunks(callable $callback): int
    {
        $total = 0;

        do {
            $deleted = (int) $callback();
            $total += $deleted;
        } while ($deleted > 0);

        return $total;
    }
}
