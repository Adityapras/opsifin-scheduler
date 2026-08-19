<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesSchedulerFixtures;
use Tests\TestCase;

class ClientCredentialStorageTest extends TestCase
{
    use CreatesSchedulerFixtures, RefreshDatabase;

    public function test_client_credentials_are_stored_as_entered(): void
    {
        $client = $this->schedule()->client;
        $stored = DB::table('clients')->where('id', $client->id)->first();

        $this->assertSame('super-secret', $stored->auth_secret);
        $this->assertSame('secret-key', $stored->auth_secret_key);
    }

    public function test_legacy_encrypted_credentials_are_converted_to_plaintext(): void
    {
        $client = $this->schedule()->client;

        DB::table('clients')->where('id', $client->id)->update([
            'auth_secret' => Crypt::encryptString('legacy-password'),
            'auth_secret_key' => Crypt::encryptString('legacy-secret-key'),
        ]);

        $migration = require database_path('migrations/2026_08_19_000005_store_client_credentials_as_plaintext.php');
        $migration->up();

        $stored = DB::table('clients')->where('id', $client->id)->first();

        $this->assertSame('legacy-password', $stored->auth_secret);
        $this->assertSame('legacy-secret-key', $stored->auth_secret_key);
    }

    public function test_conversion_stops_when_a_legacy_ciphertext_cannot_be_decrypted(): void
    {
        $client = $this->schedule()->client;
        $payload = json_decode(base64_decode(Crypt::encryptString('legacy-password')), true);
        $payload['mac'] = str_repeat('0', 64);

        DB::table('clients')->where('id', $client->id)->update([
            'auth_secret' => base64_encode(json_encode($payload)),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be decrypted');

        $migration = require database_path('migrations/2026_08_19_000005_store_client_credentials_as_plaintext.php');
        $migration->up();
    }
}
