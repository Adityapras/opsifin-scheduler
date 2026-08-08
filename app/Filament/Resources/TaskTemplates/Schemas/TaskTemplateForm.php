<?php

namespace App\Filament\Resources\TaskTemplates\Schemas;

use App\Enums\HttpMethod;
use App\Filament\Support\PreviewBox;
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
                Section::make('Identity')
                    ->columns(2)
                    ->schema([
                        TextInput::make('key')
                            ->label('Key')
                            ->helperText('Used in the lock key and in the crontab line comment. snake_case.')
                            ->required()
                            ->maxLength(96)
                            ->rule('regex:/^[a-z0-9_]+$/')
                            ->unique(ignoreRecord: true),

                        TextInput::make('name')
                            ->label('Name')
                            ->required(),

                        Textarea::make('description')
                            ->label('Description')
                            ->rows(2)
                            ->columnSpanFull(),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ]),

                Section::make('Request')
                    ->description('Defaults for every client. Individual clients can override them.')
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
                            ->helperText('Sent as-is with Content-Type: application/json.')
                            ->columnSpanFull(),

                        KeyValue::make('headers')
                            ->label('Extra headers')
                            ->keyLabel('Name')
                            ->valueLabel('Value')
                            ->helperText(new HtmlString(
                                'Authorization, Content-Type and Accept are filled in automatically. '.
                                'Use <code>{{client.secret_key}}</code>, <code>{{client.username}}</code> '.
                                'or <code>{{client.code}}</code> for values that differ per client.'
                            ))
                            ->live()
                            ->columnSpanFull(),
                    ]),

                Section::make('Timeout & retry')
                    ->description('Not a single one of the old scripts had a timeout — here it is mandatory.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('default_timeout_sec')
                            ->label('Timeout (seconds)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(3600)
                            ->required()
                            ->default(config('opsifin_cron.defaults.timeout_sec')),

                        TextInput::make('default_connect_timeout_sec')
                            ->label('Connect timeout (seconds)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(300)
                            ->required()
                            ->default(config('opsifin_cron.defaults.connect_timeout_sec')),

                        TextInput::make('default_retries')
                            ->label('Retries')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(5)
                            ->required()
                            ->default(config('opsifin_cron.defaults.retries'))
                            ->helperText('0 = no retry.'),
                    ]),

                Section::make('Preview')
                    ->description('The equivalent command the runner will execute for the selected client.')
                    ->schema([
                        Select::make('preview_client')
                            ->label('Sample client')
                            ->options(fn () => Client::orderBy('code')->pluck('code', 'id'))
                            ->searchable()
                            ->live()
                            ->dehydrated(false),

                        Text::make(fn (Get $get) => PreviewBox::make(self::previewCurl($get)))
                            ->columnSpanFull(),
                    ]),

                Section::make('Legacy origin')
                    ->description('Filled in by the importer. For migration tracing only.')
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        TextInput::make('legacy_job_file')->label('Gateway job file')->disabled(),
                        Toggle::make('legacy_gateway_routed')->label('Routed through gateway.sh')->disabled(),
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
                    '{{client.secret_key}}' => (string) $client->auth_secret_key,
                    '{{client.username}}' => (string) $client->auth_username,
                    '{{client.code}}' => $client->code,
                ])
                : (string) $value;
        }

        // Nilai asli, bukan samaran: preview ini hanya terbuka untuk role yang
        // boleh mengubah template, dan gunanya justru mencocokkan header dengan
        // script legacy.
        if ($client && $authorization = $client->authorizationHeader()) {
            $headers['Authorization'] = $authorization;
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
