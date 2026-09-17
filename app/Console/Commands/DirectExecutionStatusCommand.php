<?php

namespace App\Console\Commands;

use App\Services\Scheduling\DirectExecutionHealth;
use Illuminate\Console\Command;

class DirectExecutionStatusCommand extends Command
{
    protected $signature = 'jobs:direct-status {--json : Output metrics as JSON}';

    protected $description = 'Inspect direct executor heartbeat, timing, failures and expired occurrences';

    public function handle(DirectExecutionHealth $health): int
    {
        $snapshot = $health->snapshot();
        $this->line(json_encode($snapshot, JSON_THROW_ON_ERROR | ($this->option('json') ? 0 : JSON_PRETTY_PRINT)));

        return $snapshot['driver'] === 'direct' && $snapshot['alerts'] !== [] ? self::FAILURE : self::SUCCESS;
    }
}
