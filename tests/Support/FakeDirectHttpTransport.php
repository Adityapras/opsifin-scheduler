<?php

namespace Tests\Support;

use App\Services\Execution\DirectHttpTransport;
use App\Services\Execution\Dto\ExecutionResult;
use App\Services\Execution\Dto\RunExecution;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;

class FakeDirectHttpTransport extends DirectHttpTransport
{
    public array $sent = [];

    public array $pending = [];

    public int $maxActive = 0;

    public int $ticks = 0;

    public $onTick = null;

    public function __construct() {}

    public function send(RunExecution $execution): PromiseInterface
    {
        $this->sent[$execution->run->id] = $execution;
        $promise = new Promise;
        $this->pending[$execution->run->id] = $promise;
        $this->maxActive = max($this->maxActive, count($this->pending));

        return $promise;
    }

    public function tick(): void
    {
        $this->ticks++;
        if ($this->onTick !== null) {
            ($this->onTick)($this);

            return;
        }
        foreach (array_keys($this->pending) as $id) {
            $this->settle($id);
        }
    }

    public function settle(int $id, ?ExecutionResult $result = null): void
    {
        $this->pending[$id]->resolve($result ?? new ExecutionResult(true, 200, 'done', null, 1));
        unset($this->pending[$id]);
    }
}
