<?php

namespace App\Services\Scheduling;

use App\Enums\RunStatus;
use App\Jobs\ExecuteRun;
use App\Models\AuditLog;
use App\Models\Run;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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

            $queueJobIds = $this->removeQueuePayloads($lockedRun);

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

    /** @return array<int, int|string> */
    private function removeQueuePayloads(Run $run): array
    {
        $connectionName = (string) config('queue.default');
        $driver = config("queue.connections.{$connectionName}.driver");

        if ($driver === 'redis') {
            $removed = $this->removeRedisQueuePayloads($run, $connectionName);

            // A queued run created before the Redis cutover can still reference
            // an old database payload. Remove it while the legacy table exists.
            if ($run->queue_job_id !== null && ctype_digit($run->queue_job_id)) {
                $removed = array_merge(
                    $removed,
                    $this->removeDatabaseQueuePayloads([(int) $run->queue_job_id]),
                );
            }

            return array_values($removed);
        }

        if ($driver === 'database') {
            $ids = $run->queue_job_id === null
                ? $this->findLegacyQueueJobIds($run)
                : [(int) $run->queue_job_id];

            return $this->removeDatabaseQueuePayloads($ids);
        }

        throw new InvalidArgumentException("Queued run cancellation is not supported for [{$driver}] queues.");
    }

    /** @return array<int, int> */
    private function removeDatabaseQueuePayloads(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        DB::table(config('queue.connections.database.table', 'jobs'))
            ->whereIn('id', $ids)
            ->delete();

        return $ids;
    }

    /** @return array<int, string> */
    private function removeRedisQueuePayloads(Run $run, string $connectionName): array
    {
        $queue = Queue::connection($connectionName);

        if (! $queue instanceof RedisQueue) {
            throw new InvalidArgumentException("Queue connection [{$connectionName}] is not a Redis queue.");
        }

        $queueName = $run->schedule?->queue ?? config("queue.connections.{$connectionName}.queue", 'default');
        $redis = $queue->getConnection();
        $queueKey = $queue->getQueue($queueName);
        $removed = [];

        foreach ($redis->lrange($queueKey, 0, -1) as $payload) {
            if (! is_string($payload) || ! $this->payloadBelongsToRun($payload, $run->id)) {
                continue;
            }

            if ((int) $redis->lrem($queueKey, 1, $payload) === 0) {
                continue;
            }

            $removed[] = (string) (json_decode($payload, true)['id'] ?? 'run:'.$run->id);
        }

        return $removed;
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
