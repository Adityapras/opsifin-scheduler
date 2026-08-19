<?php

namespace Tests\Feature;

use App\Services\Execution\HttpExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\CreatesSchedulerFixtures;
use Tests\TestCase;

class HttpExecutorTest extends TestCase
{
    use CreatesSchedulerFixtures, RefreshDatabase;

    public function test_it_resolves_placeholders_and_masks_credentials_in_preview(): void
    {
        $schedule = $this->schedule([], [
            'config' => [
                'method' => 'POST',
                'path' => '/v2/run?token={{client.secret}}',
                'headers' => ['X-Client' => '{{client.code}}'],
                'body' => '{"key":"{{client.secret_key}}","legacy":"{{client.password}}"}',
            ],
            'timeout_sec' => 15,
        ]);

        $executor = app(HttpExecutor::class);
        $request = $executor->resolve($schedule->taskTemplate, $schedule->client);
        $preview = $executor->describe($request);

        $this->assertSame('https://client.example.test/v2/run?token=super-secret', $request->url);
        $this->assertSame(15, $request->timeoutSec);
        $this->assertSame($schedule->client->code, $request->headers['X-Client']);
        $this->assertStringContainsString('"legacy":"super-secret"', $request->body);
        $this->assertStringNotContainsString('super-secret', $preview);
        $this->assertStringNotContainsString('secret-key', $preview);
        $this->assertStringContainsString('Authorization: ••••••••', $preview);
    }

    public function test_credentials_are_encrypted_and_http_results_are_normalized(): void
    {
        $schedule = $this->schedule();
        $stored = DB::table('clients')->where('id', $schedule->client_id)->value('auth_secret');
        $this->assertNotSame('super-secret', $stored);

        Http::fake(['client.example.test/*' => Http::response('{"ok":true}', 200)]);
        $executor = app(HttpExecutor::class);
        $result = $executor->execute($executor->resolve($schedule->taskTemplate, $schedule->client));

        $this->assertTrue($result->success);
        $this->assertSame(200, $result->statusCode);
        $this->assertSame('{"ok":true}', $result->outputExcerpt);
    }

    public function test_invalid_resolved_url_does_not_expose_a_secret(): void
    {
        $schedule = $this->schedule([], [
            'config' => [
                'method' => 'POST',
                'path' => '/{{client.secret}}',
                'base_url' => 'not-a-url',
            ],
        ]);

        try {
            app(HttpExecutor::class)->resolve($schedule->taskTemplate, $schedule->client);
            $this->fail('Resolving an invalid URL should throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('super-secret', $exception->getMessage());
            $this->assertSame(
                'The resolved request URL is invalid. Check the client base URL and template path.',
                $exception->getMessage(),
            );
        }
    }
}
