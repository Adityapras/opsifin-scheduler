<?php

namespace App\Services\Execution;

use App\Services\Execution\Dto\ExecutionResult;
use App\Services\Execution\Dto\RunExecution;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class DirectHttpTransport
{
    private readonly Client $client;

    private readonly CurlMultiHandler $handler;

    public function __construct()
    {
        $this->handler = new CurlMultiHandler(['select_timeout' => 0.05]);
        $this->client = new Client(['handler' => HandlerStack::create($this->handler)]);
    }

    public function send(RunExecution $execution): PromiseInterface
    {
        $request = $execution->request;
        $sink = new BoundedResponseStream(max(1, (int) config('opsifin_cron.direct.response_max_bytes')));

        // No retry/redirect middleware and no credential-bearing Telescope client events.
        return $this->client->requestAsync($request->method, $request->url, [
            'headers' => array_merge(['Accept' => 'application/json', 'Content-Type' => 'application/json'], $request->headers),
            'body' => $request->body,
            'connect_timeout' => max(1, $request->connectTimeoutSec),
            'timeout' => max(1, $request->timeoutSec),
            'http_errors' => false,
            'allow_redirects' => false,
            'sink' => $sink,
        ])->then(
            function (ResponseInterface $response) use ($execution, $sink): ExecutionResult {
                $body = $execution->request->redact(mb_convert_encoding((string) $sink, 'UTF-8', 'UTF-8'));
                $status = $response->getStatusCode();
                $success = $status >= 200 && $status < 300;
                $message = null;
                if (! $success) {
                    $json = json_decode($body, true);
                    $detail = is_array($json) ? ($json['message'] ?? $json['error'] ?? null) : null;
                    $message = 'HTTP '.$status.' '.$response->getReasonPhrase();
                    if (is_string($detail) || is_numeric($detail)) {
                        $message .= ': '.$detail;
                    }
                }

                return new ExecutionResult($success, $status, $body, $message, $this->duration($execution));
            },
            fn (Throwable $error) => new ExecutionResult(false, null, null,
                $execution->request->redact($error->getMessage()), $this->duration($execution)),
        );
    }

    public function tick(): void
    {
        $this->handler->tick();
        Utils::queue()->run();
    }

    private function duration(RunExecution $execution): int
    {
        return (int) round((microtime(true) - $execution->started) * 1000);
    }
}
