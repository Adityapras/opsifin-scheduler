<?php

namespace App\Services\Scheduling;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Services\Execution\DirectHttpTransport;
use App\Services\Execution\Dto\ExecutionResult;
use App\Services\Execution\Dto\RunExecution;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class DirectPoolExecutor
{
    /** @var array<int, array{execution: RunExecution, promise: PromiseInterface}> */
    private array $active = [];

    private bool $stopping = false;

    public function __construct(
        private readonly RunExecutionLifecycle $lifecycle,
        private readonly DirectHttpTransport $transport,
        private readonly DirectExecutorLease $lease,
        private readonly DueScheduleDispatcher $dispatcher,
    ) {}

    public function stop(): void
    {
        $this->stopping = true;
    }

    /** --once drains up to batch_limit; the daemon refills across polling cycles. */
    public function work(bool $once = false, int $maxSeconds = 0): array
    {
        foreach (['concurrency', 'batch_limit', 'poll_interval_ms', 'start_window_sec', 'heartbeat_sec', 'idle_delay_ms', 'response_max_bytes'] as $key) {
            if ((int) config('opsifin_cron.direct.'.$key) < 1) {
                throw new InvalidArgumentException('Direct configuration must be positive: '.$key);
            }
        }
        if ((int) config('opsifin_cron.direct.start_window_sec') >= 60) {
            throw new InvalidArgumentException('Direct start window must be less than 60 seconds.');
        }
        $stats = ['started' => 0, 'settled' => 0, 'missed_start_window' => 0, 'expired_running' => 0, 'max_active' => 0];
        if (! in_array(config('opsifin_cron.execution_driver'), ['queue', 'direct'], true)) {
            throw new InvalidArgumentException('Unsupported execution driver.');
        }
        if (config('opsifin_cron.execution_driver') !== 'direct') {
            return $stats + ['standby' => true];
        }
        $stats['expired_running'] += $this->dispatcher->recoverExpiredRuns(now());
        if (! $this->lease->acquire()) {
            return $stats + ['busy' => true];
        }
        $this->stopping = false;
        $started = microtime(true);
        $lastPoll = $lastHeartbeat = 0.0;
        $nextAdmissionPoll = 0.0;
        $capacity = (int) config('opsifin_cron.direct.concurrency');
        $limit = (int) config('opsifin_cron.direct.batch_limit');
        $failed = false;
        try {
            do {
                $now = microtime(true);
                if ($maxSeconds > 0 && $now - $started >= $maxSeconds) {
                    $this->stop();
                }
                if ($now - $lastHeartbeat >= (int) config('opsifin_cron.direct.heartbeat_sec')) {
                    $this->lease->heartbeat($stats + [
                        'pool_active' => count($this->active), 'pool_capacity' => $capacity,
                        'pool_pending' => Run::query()->where('status', RunStatus::Pending->value)->count(),
                        'pool_saturation' => count($this->active) / $capacity,
                        'batch_duration_ms' => (int) (($now - $started) * 1000),
                    ]);
                    $lastHeartbeat = $now;
                }
                if (! $this->stopping && $now - $lastPoll >= (int) config('opsifin_cron.direct.poll_interval_ms') / 1000) {
                    $stats['missed_start_window'] += $this->dispatcher->expirePendingRuns();
                    $stats['expired_running'] += $this->dispatcher->recoverExpiredRuns(now());
                    $lastPoll = $now;
                }
                $room = $capacity - count($this->active);
                $budget = $once ? min($room, $limit - $stats['started']) : min($room, $limit);
                $ids = [];
                $queried = false;
                if (! $this->stopping && $budget > 0 && $now >= $nextAdmissionPoll) {
                    $queried = true;
                    $ids = Run::query()->where('status', RunStatus::Pending->value)->where('execution_driver', 'direct')
                        ->where('scheduled_for', '<=', now())
                        ->where('scheduled_for', '>', now()->subSeconds(config('opsifin_cron.direct.start_window_sec')))
                        ->orderBy('scheduled_for')->orderBy('id')->limit($budget)->pluck('id')->all();
                    $nextAdmissionPoll = $ids === []
                        ? $now + (int) config('opsifin_cron.direct.poll_interval_ms') / 1000
                        : 0.0;
                    foreach ($ids as $id) {
                        if ($this->stopping) {
                            break;
                        }
                        $execution = $this->lease->admit(fn () => $this->lifecycle->claim($id, RunStatus::Pending));
                        if ($execution === null) {
                            continue;
                        }
                        $stats['started']++;
                        try {
                            $promise = $this->transport->send($execution);
                            $this->active[$id] = compact('execution', 'promise');
                        } catch (Throwable $error) {
                            $this->lifecycle->fail($execution, $error->getMessage());
                            $stats['settled']++;
                        }
                    }
                }
                $stats['max_active'] = max($stats['max_active'], count($this->active));
                $this->transport->tick();
                foreach ($this->active as $id => $entry) {
                    if ($entry['promise']->getState() === PromiseInterface::PENDING) {
                        continue;
                    }
                    try {
                        $result = $entry['promise']->wait();
                        if (! $result instanceof ExecutionResult) {
                            throw new \RuntimeException('HTTP transport returned no execution result.');
                        }
                        $this->lifecycle->complete($entry['execution'], $result);
                    } catch (Throwable) {
                        // A failed persistence callback must not prevent other callbacks.
                        $failed = true;
                        $this->stop();
                        try {
                            $this->lifecycle->fail($entry['execution'], 'Unable to persist HTTP result; endpoint outcome is unknown. No retry.');
                        } catch (Throwable) {
                            Log::error('Direct result persistence failed; deadline recovery required.', ['run_id' => $id]);
                        }
                    }
                    unset($this->active[$id]);
                    $stats['settled']++;
                }
                if ($this->active === [] && ($this->stopping || ($once && (($queried && $ids === []) || $stats['started'] >= $limit)))) {
                    break;
                }
                if ($this->active === []) {
                    usleep((int) config('opsifin_cron.direct.idle_delay_ms') * 1000);
                }
            } while (true);
            $this->lease->heartbeat($stats + ['pool_active' => 0, 'pool_capacity' => $capacity,
                'batch_duration_ms' => (int) ((microtime(true) - $started) * 1000)]);
        } finally {
            foreach ($this->active as $id => $entry) {
                $entry['promise']->cancel();
                try {
                    $this->lifecycle->fail($entry['execution'], 'Executor stopped while HTTP may have been sent; endpoint outcome is unknown. No retry.');
                } catch (Throwable) {
                    Log::error('Direct final sweep deferred to deadline recovery.', ['run_id' => $id]);
                }
            }
            $this->active = [];
            $this->lease->release();
        }

        return $stats + ['failed' => $failed];
    }
}
