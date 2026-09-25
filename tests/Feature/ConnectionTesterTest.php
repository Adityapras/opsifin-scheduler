<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\Pages\ListClients;
use App\Services\ConnectionTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesSchedulerFixtures;
use Tests\TestCase;

class ConnectionTesterTest extends TestCase
{
    use CreatesSchedulerFixtures, RefreshDatabase;

    public function test_probe_sends_basic_auth_to_the_credential_endpoint_with_get(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Connection Success'], 200)]);
        $client = $this->schedule()->client;

        $result = app(ConnectionTester::class)->test($client);

        $this->assertTrue($result['ok']);
        $this->assertSame('Credentials valid', $result['status']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://client.example.test/api/remittanceApi'
            && $request->header('Authorization')[0] === 'Basic '.base64_encode('api-user:super-secret'));
    }

    /** @return array<string, array{int, bool, string}> */
    public static function statusProvider(): array
    {
        return [
            'accepted' => [200, true, 'Credentials valid'],
            'auth passed, no GET handler' => [405, true, 'Credentials valid'],
            'unauthorized' => [401, false, 'Credentials rejected'],
            'forbidden' => [403, false, 'Credentials rejected'],
            'missing endpoint' => [404, false, 'Check unavailable'],
            'server error' => [500, false, 'Client server error'],
            'redirect' => [302, false, 'Unexpected response'],
        ];
    }

    #[DataProvider('statusProvider')]
    public function test_http_status_is_classified(int $status, bool $ok, string $label): void
    {
        Http::fake(['*' => Http::response('', $status)]);

        $result = app(ConnectionTester::class)->test($this->schedule()->client);

        $this->assertSame($ok, $result['ok']);
        $this->assertSame($label, $result['status']);
        $this->assertSame($status, $result['http_status']);
    }

    public function test_connection_failure_is_reported_without_credentials(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host: client.example.test'));

        $result = app(ConnectionTester::class)->test($this->schedule()->client);

        $this->assertFalse($result['ok']);
        $this->assertSame('Cannot connect', $result['status']);
        $this->assertStringNotContainsString('super-secret', $result['detail']);
    }

    public function test_secret_key_is_reported_as_not_verified(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $result = app(ConnectionTester::class)->test($this->schedule()->client);

        $this->assertStringContainsString('SecretKey header cannot be verified', $result['detail']);
    }

    public function test_table_action_runs_the_credential_check(): void
    {
        Http::fake(['*' => Http::response('', 401)]);
        $client = $this->schedule()->client;

        Livewire::actingAs($this->user())->test(ListClients::class)
            ->callTableAction('test', $client)
            ->assertNotified($client->code.' — Credentials rejected');
    }
}
