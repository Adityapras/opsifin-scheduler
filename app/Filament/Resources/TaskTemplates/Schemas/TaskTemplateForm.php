<?php

namespace App\Filament\Resources\TaskTemplates\Schemas;

use App\Enums\ExecutorType;
use App\Enums\HttpMethod;
use Cron\CronExpression;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class TaskTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Job template')
                ->description('Define an HTTP job once, then assign it to many clients.')
                ->columns(2)
                ->schema([
                    TextInput::make('key')
                        ->helperText('Stable snake_case identifier, for example update_balance.')
                        ->required()->maxLength(96)->rule('regex:/^[a-z0-9_]+$/')->unique(ignoreRecord: true),
                    TextInput::make('name')->required()->maxLength(255),
                    Textarea::make('description')->rows(2)->columnSpanFull(),
                    Select::make('executor')
                        ->options([ExecutorType::Http->value => ExecutorType::Http->label()])
                        ->default(ExecutorType::Http->value)->required(),
                    Toggle::make('is_active')
                        ->label('Template active')
                        ->helperText('Pausing a template prevents every assigned schedule from executing.')
                        ->default(true),
                ]),

            Section::make('HTTP request')
                ->description('The client supplies the base URL and credentials at runtime.')
                ->columns(3)
                ->schema([
                    Select::make('config.method')
                        ->label('Method')
                        ->options(collect(HttpMethod::cases())->mapWithKeys(fn (HttpMethod $method) => [$method->value => $method->value]))
                        ->default(HttpMethod::Post->value)->required(),
                    TextInput::make('config.path')
                        ->label('Path')->prefix('{client.base_url}')->placeholder('/api/tasks/reconcile')
                        ->required()->columnSpan(2),
                    Textarea::make('config.body')
                        ->label('JSON body')->rows(5)
                        ->helperText(new HtmlString('Supports <code>{{client.code}}</code>, <code>{{client.username}}</code>, and <code>{{run.scheduled_for}}</code>.'))
                        ->columnSpanFull(),
                    KeyValue::make('config.headers')
                        ->label('Additional headers')->keyLabel('Header')->valueLabel('Value')
                        ->helperText(new HtmlString('Use <code>{{client.secret_key}}</code> for the client secret key. Authorization is added automatically.'))
                        ->columnSpanFull(),
                ]),

            Section::make('Default schedule')
                ->description('Controls how this service is assigned when a new client is created.')
                ->columns(2)
                ->schema([
                    Toggle::make('auto_assign_to_new_clients')
                        ->label('Assign to new clients')
                        ->helperText('Creates one paused schedule for this service during client setup.')
                        ->default(true),
                    Toggle::make('default_schedule_enabled')
                        ->label('Enable immediately')
                        ->helperText('Keep this off so credentials and request previews can be reviewed first.')
                        ->default(false),
                    TextInput::make('default_cron_expression')
                        ->label('Default cron expression')
                        ->helperText('Applied to newly provisioned clients. Existing schedules are not changed.')
                        ->default('*/5 * * * *')
                        ->required()
                        ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                            if (! CronExpression::isValidExpression((string) $value)) {
                                $fail('The default cron expression is not valid.');
                            }
                        }),
                    Toggle::make('default_prevent_overlap')
                        ->label('Prevent overlapping runs')
                        ->helperText('Skip a new occurrence while the previous run is still active.')
                        ->default(true),
                ]),

            Section::make('Timeouts')
                ->description('Each queued run performs one HTTP attempt. Failed runs are retried manually.')
                ->columns(2)
                ->schema([
                    TextInput::make('connect_timeout_sec')
                        ->label('Connect timeout')->suffix('sec')
                        ->numeric()->minValue(1)->maxValue(300)->required()
                        ->default(config('opsifin_cron.defaults.connect_timeout_sec')),
                    TextInput::make('timeout_sec')
                        ->label('Request timeout')->suffix('sec')
                        ->numeric()->minValue(1)->maxValue(1800)->required()
                        ->default(config('opsifin_cron.defaults.timeout_sec')),
                ]),

            Section::make('Migration trace')
                ->collapsed()->columns(2)
                ->schema([
                    TextInput::make('legacy_job_file')->disabled(),
                    Toggle::make('legacy_gateway_routed')->disabled(),
                    Toggle::make('needs_review')->label('Needs manual review'),
                    Textarea::make('review_notes')->rows(2)->columnSpanFull(),
                ]),
        ]);
    }
}
