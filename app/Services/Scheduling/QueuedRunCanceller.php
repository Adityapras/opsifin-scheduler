<?php

namespace App\Services\Scheduling;

use App\Enums\RunStatus;
use App\Jobs\ExecuteRun;
use App\Models\AuditLog;
use App\Models\Run;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class QueuedRunCanceller
{
    public function cancel(Run $run): Run
    {
        return DB::transaction(function () use ($run): Run {
            $lockedRun = Run::query()->lockForUpdate()->findOrFail($run->getKey());

            if ($lockedRun->status !== RunStatus::Queued) {
                throw new InvalidArgumentException('Only a queued run can be cancelled.');
            }

            $queueJobIds = $lockedRun->queue_job_id === null
                ? $this->findLegacyQueueJobIds($lockedRun)
                : [(int) $lockedRun->queue_job_id];

            if ($queueJobIds !== []) {
                DB::table(config('queue.connections.database.table', 'jobs'))
                    ->whereIn('id', $queueJobIds)
                    ->delete();
            }

            $before = ['status' => $lockedRun->status->value];
            $lockedRun->forceFill([
                'status' => RunStatus::Cancelled,
                'finished_at' => now(),
                'execution_deadline_at' => null,
                'duration_ms' => 0,
                'error_message' => 'Cancelled before execution.',
            ])->save();

            AuditLog::record('cancelled', $lockedRun, $before, [
                'status' => RunStatus::Cancelled->value,
                'queue_job_ids' => $queueJobIds,
            ]);

            return $lockedRun;
        });
    }

    /** @return array<int, int> */
    private function findLegacyQueueJobIds(Run $run): array
    {
        $queue = $run->schedule?->queue ?? 'default';

        return DB::table(config('queue.connections.database.table', 'jobs'))
            ->where('queue', $queue)
            ->get(['id', 'payload'])
            ->filter(fn (object $record): bool => $this->payloadBelongsToRun($record->payload, $run->id))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function payloadBelongsToRun(string $payload, int $runId): bool
    {
        $decoded = json_decode($payload, true);
        $serialized = $decoded['data']['command'] ?? null;

        if (! is_string($serialized)) {
            return false;
        }

        $job = @unserialize($serialized, ['allowed_classes' => [ExecuteRun::class]]);

        return $job instanceof ExecuteRun && $job->runId === $runId;
    }
}
