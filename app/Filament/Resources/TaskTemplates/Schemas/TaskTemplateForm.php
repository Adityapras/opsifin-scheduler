<?php

namespace App\Filament\Resources\TaskTemplates\Schemas;

use App\Enums\HttpMethod;
use App\Models\Client;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class TaskTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identitas')
                    ->columns(2)
                    ->schema([
                        TextInput::make('key')
                            ->label('Key')
                            ->helperText('Dipakai di lock key dan di komentar baris crontab. snake_case.')
                            ->required()
                            ->maxLength(96)
                            ->rule('regex:/^[a-z0-9_]+$/')
                            ->unique(ignoreRecord: true),

                        TextInput::make('name')
                            ->label('Nama')
                            ->required(),

                        Textarea::make('description')
                            ->label('Deskripsi')
                            ->rows(2)
                            ->columnSpanFull(),

                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true),
                    ]),

                Section::make('Request')
                    ->description('Nilai default untuk semua client. Client bisa menimpanya lewat override.')
                    ->columns(3)
                    ->schema([
                        Select::make('http_method')
                            ->label('Method')
                            ->options(collect(HttpMethod::cases())->mapWithKeys(fn ($m) => [$m->value => $m->value]))
                            ->default(HttpMethod::Post->value)
                            ->live()
                            ->required(),

                        TextInput::make('path_template')
                            ->label('Path')
                            ->prefix('{base_url}')
                            ->placeholder('/apiv_g/api_repost')
                            ->required()
                            ->live(onBlur: true)
                            ->columnSpan(2),

                        Textarea::make('body_template')
                            ->label('Body')
                            ->rows(3)
                            ->live(onBlur: true)
                            ->helperText('Dikirim apa adanya dengan Content-Type: application/json.')
                            ->columnSpanFull(),

                        KeyValue::make('headers')
                            ->label('Header tambahan')
                            ->keyLabel('Nama')
                            ->valueLabel('Nilai')
                            ->helperText(new HtmlString(
                                'Authorization, Content-Type, dan Accept diisi otomatis. '.
                                'Pakai <code>{{client.secret_key}}</code>, <code>{{client.username}}</code>, '.
                                'atau <code>{{client.code}}</code> untuk nilai yang berbeda tiap client.'
                            ))
                            ->live()
                            ->columnSpanFull(),
                    ]),

                Section::make('Timeout & retry')
                    ->description('Tidak satu pun script lama punya timeout — di sini wajib ada.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('default_timeout_sec')
                            ->label('Timeout (detik)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(3600)
                            ->required()
                            ->default(config('opsifin_cron.defaults.timeout_sec')),

                        TextInput::make('default_connect_timeout_sec')
                            ->label('Connect timeout (detik)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(300)
                            ->required()
                            ->default(config('opsifin_cron.defaults.connect_timeout_sec')),

                        TextInput::make('default_retries')
                            ->label('Retry')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(5)
                            ->required()
                            ->default(config('opsifin_cron.defaults.retries'))
                            ->helperText('0 = tidak diulang.'),
                    ]),

                Section::make('Preview')
                    ->description('Perintah setara yang akan dijalankan runner untuk client terpilih.')
                    ->schema([
                        Select::make('preview_client')
                            ->label('Contoh client')
                            ->options(fn () => Client::orderBy('code')->pluck('code', 'id'))
                            ->searchable()
                            ->live()
                            ->dehydrated(false),

                        Text::make(fn (Get $get) => new HtmlString(
                            '<pre class="overflow-x-auto whitespace-pre-wrap rounded bg-gray-950/5 p-3 text-xs dark:bg-white/5">'
                            .e(self::previewCurl($get))
                            .'</pre>'
                        ))->columnSpanFull(),
                    ]),

                Section::make('Asal data legacy')
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        TextInput::make('legacy_job_file')->label('File job gateway')->disabled(),
                        Toggle::make('legacy_gateway_routed')->label('Dirouting gateway.sh')->disabled(),
                    ]),
            ]);
    }

    private static function previewCurl(Get $get): string
    {
        $client = Client::find($get('preview_client'));
        $baseUrl = $client?->base_url ?? '{base_url}';
        $method = $get('http_method') ?: 'POST';
        $path = '/'.ltrim((string) $get('path_template'), '/');
        $body = $get('body_template');

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        foreach ((array) $get('headers') as $name => $value) {
            if (blank($name)) {
                continue;
            }

            $headers[$name] = $client
                ? strtr((string) $value, [
                    '{{client.secret_key}}' => '••• (secret key '.$client->code.')',
                    '{{client.username}}' => (string) $client->auth_username,
                    '{{client.code}}' => $client->code,
                ])
                : (string) $value;
        }

        if ($client && $client->authorizationHeader()) {
            $headers['Authorization'] = $client->auth_type->value === 'basic'
                ? 'Basic ••• ('.$client->auth_username.')'
                : 'Bearer •••';
        }

        $lines = ['curl -i \\'];
        $lines[] = sprintf('  --connect-timeout %s --max-time %s \\',
            $get('default_connect_timeout_sec') ?: 10,
            $get('default_timeout_sec') ?: 60);

        foreach ($headers as $name => $value) {
            $lines[] = sprintf('  -H %s \\', escapeshellarg($name.': '.$value));
        }

        $lines[] = sprintf('  -X %s \\', $method);

        if (filled($body)) {
            $lines[] = sprintf('  -d %s \\', escapeshellarg((string) $body));
        }

        $lines[] = '  '.escapeshellarg(rtrim($baseUrl, '/').$path);

        return implode("\n", $lines);
    }
}
