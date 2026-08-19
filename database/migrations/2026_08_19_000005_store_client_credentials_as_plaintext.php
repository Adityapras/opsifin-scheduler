<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('clients')
            ->select(['id', 'auth_secret', 'auth_secret_key'])
            ->orderBy('id')
            ->chunkById(100, function ($clients): void {
                foreach ($clients as $client) {
                    $values = [];

                    foreach (['auth_secret', 'auth_secret_key'] as $column) {
                        $value = $client->{$column};

                        if (! is_string($value) || ! $this->looksLikeEncryptedPayload($value)) {
                            continue;
                        }

                        try {
                            $values[$column] = Crypt::decryptString($value);
                        } catch (DecryptException $exception) {
                            throw new RuntimeException(
                                "Client {$client->id} contains an encrypted {$column} value that cannot be decrypted. "
                                .'Run this migration once with the APP_KEY that encrypted the existing data before creating the production dump.',
                                previous: $exception,
                            );
                        }
                    }

                    if ($values !== []) {
                        DB::table('clients')->where('id', $client->id)->update($values);
                    }
                }
            });
    }

    public function down(): void
    {
        DB::table('clients')
            ->select(['id', 'auth_secret', 'auth_secret_key'])
            ->orderBy('id')
            ->chunkById(100, function ($clients): void {
                foreach ($clients as $client) {
                    $values = [];

                    foreach (['auth_secret', 'auth_secret_key'] as $column) {
                        $value = $client->{$column};

                        if (is_string($value)) {
                            $values[$column] = Crypt::encryptString($value);
                        }
                    }

                    if ($values !== []) {
                        DB::table('clients')->where('id', $client->id)->update($values);
                    }
                }
            });
    }

    private function looksLikeEncryptedPayload(string $value): bool
    {
        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            return false;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload)
            && isset($payload['iv'], $payload['value'], $payload['mac']);
    }
};
