<?php

namespace App\Filament\Resources\Clients\Actions;

use App\Models\Client;
use App\Services\ConnectionTester;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class TestConnectionAction
{
    /** Aksi baris tabel: menguji credential yang sudah tersimpan. */
    public static function forRecord(): Action
    {
        return Action::make('test')
            ->label('Test connection')
            ->icon('heroicon-o-signal')
            ->color('gray')
            ->authorize(fn (Client $record) => auth()->user()->can('test', $record))
            ->action(fn (Client $record, ConnectionTester $tester) => self::run($record, $tester));
    }

    /**
     * Aksi form Create/Edit: menguji nilai yang sedang diisi, sebelum disimpan,
     * supaya credential baru bisa dicek tanpa menimpa yang lama.
     */
    public static function forForm(): Action
    {
        return Action::make('testConnection')
            ->label('Test connection')
            ->icon('heroicon-o-signal')
            ->color('gray')
            ->size('sm')
            ->authorize(fn (): bool => auth()->user()->canOperate())
            ->action(function ($livewire, ConnectionTester $tester): void {
                $data = $livewire->data ?? [];

                if (blank($data['base_url'] ?? null)) {
                    Notification::make()->title('Fill in the base URL first')->warning()->send();

                    return;
                }

                // Model sementara, tidak pernah disimpan.
                $client = (new Client)->forceFill([
                    'code' => $data['code'] ?? null,
                    'base_url' => $data['base_url'],
                    'auth_type' => $data['auth_type'] ?? null,
                    'auth_username' => $data['auth_username'] ?? null,
                    'auth_secret' => $data['auth_secret'] ?? null,
                    'auth_secret_key' => $data['auth_secret_key'] ?? null,
                ]);

                self::run($client, $tester, fromForm: true);
            });
    }

    private static function run(Client $client, ConnectionTester $tester, bool $fromForm = false): void
    {
        $result = $tester->test($client);
        $label = filled($client->code) ? $client->code.' — ' : '';

        Notification::make()
            ->title($label.$result['status'])
            ->body($result['detail'].' ('.$result['duration_ms'].' ms)'
                .($fromForm ? ' Tested with the values in the form; save to keep them.' : ''))
            ->status($result['ok'] ? 'success' : 'danger')
            ->persistent()
            ->send();
    }
}
