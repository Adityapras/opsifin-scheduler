<?php

namespace App\Services\Execution;

use App\Enums\HttpMethod;
use App\Models\Client;
use App\Models\Run;
use App\Models\TaskTemplate;
use App\Services\Execution\Contracts\Executor;
use App\Services\Execution\Dto\ExecutionResult;
use App\Services\Execution\Dto\ResolvedRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class HttpExecutor implements Executor
{
    public function resolve(
        TaskTemplate $template,
        Client $client,
        ?Run $run = null,
    ): ResolvedRequest {
        $config = $template->config ?? [];
        $method = strtoupper((string) ($config['method'] ?? 'POST'));

        if (HttpMethod::tryFrom($method) === null) {
            throw new RuntimeException("Unsupported HTTP method: {$method}");
        }

        $baseUrl = rtrim((string) ($config['base_url'] ?? $client->base_url), '/');
        $path = '/'.ltrim((string) ($config['path'] ?? ''), '/');
        $url = $this->replacePlaceholders($baseUrl.$path, $client, $run);

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('The resolved request URL is invalid. Check the client base URL and template path.');
        }

        $headers = [];
        foreach (($config['headers'] ?? []) as $name => $value) {
            $headers[(string) $name] = $this->replacePlaceholders((string) $value, $client, $run);
        }

        if ($authorization = $client->authorizationHeader()) {
            $headers['Authorization'] = $authorization;
        }

        $body = $config['body'] ?? null;
        if (is_array($body)) {
            $body = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        if ($body !== null) {
            $body = $this->replacePlaceholders((string) $body, $client, $run);
        }

        return new ResolvedRequest(
            method: $method,
            url: $url,
            body: $body,
            headers: $headers,
            timeoutSec: (int) $template->timeout_sec,
            connectTimeoutSec: (int) $template->connect_timeout_sec,
            sensitiveValues: array_values(array_filter([
                (string) $client->auth_secret,
                (string) $client->auth_secret_key,
            ])),
        );
    }

    public function execute(ResolvedRequest $request): ExecutionResult
    {
        $started = microtime(true);
        $limit = (int) config('opsifin_cron.response_excerpt_length', 2000);

        try {
            $pending = Http::withHeaders(array_merge([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ], $request->headers))
                ->connectTimeout($request->connectTimeoutSec)
                ->timeout($request->timeoutSec);

            if ($request->body !== null && $request->body !== '') {
                $pending = $pending->withBody($request->body, 'application/json');
            }

            $response = $pending->send($request->method, $request->url);
            $success = $response->successful();

            return new ExecutionResult(
                success: $success,
                statusCode: $response->status(),
                outputExcerpt: Str::limit($response->body(), $limit),
                errorMessage: $success ? null : 'HTTP '.$response->status().' '.$response->reason(),
                durationMs: $this->duration($started),
            );
        } catch (ConnectionException $exception) {
            return new ExecutionResult(
                success: false,
                statusCode: null,
                outputExcerpt: null,
                errorMessage: Str::limit($exception->getMessage(), 1000),
                durationMs: $this->duration($started),
            );
        } catch (Throwable $exception) {
            return new ExecutionResult(
                success: false,
                statusCode: null,
                outputExcerpt: null,
                errorMessage: Str::limit($exception->getMessage(), 1000),
                durationMs: $this->duration($started),
            );
        }
    }

    public function describe(ResolvedRequest $request, bool $revealSecrets = false): string
    {
        $lines = [$request->method.' '.($revealSecrets ? $request->url : $request->redact($request->url))];

        foreach ($request->headers as $name => $value) {
            $secret = in_array(strtolower($name), ['authorization', 'secretkey', 'x-api-key'], true);
            $lines[] = $name.': '.($secret && ! $revealSecrets
                ? '••••••••'
                : ($revealSecrets ? $value : $request->redact($value)));
        }

        $lines[] = '';
        $lines[] = ($revealSecrets ? $request->body : $request->redact($request->body)) ?? '(no body)';
        $lines[] = '';
        $lines[] = "timeout={$request->timeoutSec}s connect_timeout={$request->connectTimeoutSec}s";

        return implode("\n", $lines);
    }

    private function replacePlaceholders(string $value, Client $client, ?Run $run): string
    {
        return strtr($value, [
            '{{client.code}}' => $client->code,
            '{{client.username}}' => (string) $client->auth_username,
            '{{client.secret}}' => (string) $client->auth_secret,
            // Backward-compatible alias for templates imported before the lean rewrite.
            '{{client.password}}' => (string) $client->auth_secret,
            '{{client.secret_key}}' => (string) $client->auth_secret_key,
            '{{run.scheduled_for}}' => $run?->scheduled_for?->copy()->utc()->toIso8601String() ?? '',
        ]);
    }

    private function duration(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
