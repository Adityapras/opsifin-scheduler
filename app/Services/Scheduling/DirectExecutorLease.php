<?php

namespace App\Services\Scheduling;

use App\Enums\RunStatus;
use App\Models\Run;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** A database lease shared by every executor using this application's database. */
class DirectExecutorLease
{
    private readonly string $owner;

    public function __construct()
    {
        $this->owner = (gethostname() ?: 'executor').':'.getmypid().':'.Str::uuid();
    }

    public function acquire(): bool
    {
        return DB::transaction(function (): bool {
            $state = DB::table('executor_states')->where('name', 'direct')->lockForUpdate()->first();
            if ($state === null) {
                throw new RuntimeException('Run the compatibility migration before starting the direct executor.');
            }
            if ($state->owner !== null && $state->expires_at > now()->toDateTimeString()) {
                return false;
            }
            // After a crash, allow all old requests to reach their deadline first.
            if (Run::query()->where('execution_driver', 'direct')->where('status', RunStatus::Running->value)->exists()) {
                return false;
            }
            DB::table('executor_states')->where('name', 'direct')->update([
                'owner' => $this->owner, 'expires_at' => $this->expiry(), 'heartbeat_at' => now(),
                'metrics' => json_encode(['pool_active' => 0, 'pool_capacity' => config('opsifin_cron.direct.concurrency')]),
            ]);

            return true;
        });
    }

    public function heartbeat(array $metrics): void
    {
        if (DB::table('executor_states')->where('name', 'direct')->where('owner', $this->owner)
            ->where('expires_at', '>', now())->update([
                'expires_at' => $this->expiry(), 'heartbeat_at' => now(), 'metrics' => json_encode($metrics, JSON_THROW_ON_ERROR),
            ]) !== 1) {
            throw new RuntimeException('Direct executor lease lost; admission stopped.');
        }
    }

    public function admit(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback): mixed {
            $state = DB::table('executor_states')->where('name', 'direct')->lockForUpdate()->first();
            if ($state?->owner !== $this->owner || $state->expires_at <= now()->toDateTimeString()) {
                throw new RuntimeException('Direct executor lease lost; admission stopped.');
            }

            return $callback();
        });
    }

    public function release(): void
    {
        DB::table('executor_states')->where('name', 'direct')->where('owner', $this->owner)
            ->update(['owner' => null, 'expires_at' => null]);
    }

    private function expiry(): Carbon
    {
        return now()->addSeconds(max(30, (int) config('opsifin_cron.direct.heartbeat_sec') * 3));
    }
}
