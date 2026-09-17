<?php

namespace Tests\Support;

trait StartsDirectHttpFixture
{
    private $fixtureProcess;

    private array $fixturePipes = [];

    private string $fixtureUrl;

    private function startHttpFixture(): void
    {
        $this->fixtureProcess = proc_open(['python3', base_path('tests/Support/direct_http_fixture.py')],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->fixturePipes);
        stream_set_timeout($this->fixturePipes[1], 5);
        $port = trim((string) fgets($this->fixturePipes[1]));
        if (! ctype_digit($port)) {
            throw new \RuntimeException('Loopback HTTP fixture failed: '.stream_get_contents($this->fixturePipes[2]));
        }
        $this->fixtureUrl = 'http://127.0.0.1:'.$port;
    }

    private function stopHttpFixture(): void
    {
        if (is_resource($this->fixtureProcess)) {
            proc_terminate($this->fixtureProcess);
            foreach ($this->fixturePipes as $pipe) {
                fclose($pipe);
            }
            proc_close($this->fixtureProcess);
        }
    }

    private function httpMetrics(): array
    {
        return json_decode(file_get_contents($this->fixtureUrl.'/metrics'), true, flags: JSON_THROW_ON_ERROR);
    }
}
