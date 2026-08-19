<?php

namespace App\Filament\Resources\Schedules\Schemas;

use App\Filament\Support\PreviewBox;
use App\Models\Client;
use App\Models\TaskTemplate;
use App\Services\CronDescriber;
use App\Services\Execution\HttpExecutor;
use Cron\CronExpression;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Throwable;

class ScheduleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Assignment')
                ->description('Connect one reusable job template to one client.')
                ->columns(2)
                ->schema([
                    Select::make('client_id')
                        ->relationship('client', 'code')
                        ->getOptionLabelFromRecordUsing(fn (Client $client) => $client->code.' — '.$client->name)
                        ->searchable()->preload()->required()->live(),
                    Select::make('task_template_id')
                        ->label('Job template')->relationship('taskTemplate', 'key')
                        ->getOptionLabelFromRecordUsing(fn (TaskTemplate $task) => $task->key.' — '.($task->config['path'] ?? ''))
                        ->searchable()->preload()->required()->live(),
                    Text::make(fn (Get $get) => self::targetPreview($get))->columnSpanFull(),
                ]),

            Section::make('Timing')
                ->description('Paused periods are not replayed. Resuming calculates the next future occurrence.')
                ->columns(3)
                ->schema([
                    Select::make('preset')
                        ->label('Quick preset')
                        ->options([
                            '*/5 * * * *' => 'Every 5 minutes',
                            '*/10 * * * *' => 'Every 10 minutes',
                            '*/30 * * * *' => 'Every 30 minutes',
                            '0 * * * *' => 'Every hour',
                            '0 6 * * *' => 'Daily at 06:00',
                            '0 6 * * 1-5' => 'Weekdays at 06:00',
                        ])
                        ->placeholder('Custom expression')->dehydrated(false)->live()
                        ->afterStateUpdated(fn (?string $state, Set $set) => $state ? $set('cron_expression', $state) : null),
                    TextInput::make('cron_expression')
                        ->label('Cron expression')->placeholder('*/5 * * * *')->required()->live(onBlur: true)
                        ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                            if (! CronExpression::isValidExpression((string) $value)) {
                                $fail('The cron expression is not valid.');
                            }
                        }),
                    Select::make('timezone')
                        ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                        ->searchable()->required()->live()->default(config('opsifin_cron.default_timezone')),
                    Text::make(fn (Get $get) => self::cronPreview($get))->columnSpanFull(),
                ]),

            Section::make('State')
                ->columns(3)
                ->schema([
                    Toggle::make('is_enabled')
                        ->label('Schedule enabled')
                        ->helperText('Imported and newly bulk-assigned schedules should normally start paused.')
                        ->default(false),
                    Toggle::make('prevent_overlap')
                        ->label('Skip overlapping run')
                        ->helperText('If the previous run is still active, record the next occurrence as Skipped instead of starting another request.')
                        ->default(true),
                    Toggle::make('needs_review')->label('Needs manual review'),
                    Textarea::make('review_notes')->rows(2)->columnSpanFull(),
                ]),

            Section::make('Migration trace')
                ->collapsed()->columns(2)
                ->schema([
                    TextInput::make('legacy_pattern')->disabled(),
                    TextInput::make('legacy_line_no')->disabled(),
                    Textarea::make('legacy_command')->rows(2)->disabled()->columnSpanFull(),
                ]),
        ]);
    }

    private static function targetPreview(Get $get): Htmlable
    {
        $client = Client::find($get('client_id'));
        $task = TaskTemplate::find($get('task_template_id'));

        if (! $client || ! $task) {
            return PreviewBox::make('Select a client and job template to inspect the final request.');
        }

        try {
            $executor = app(HttpExecutor::class);
            $request = $executor->resolve($task, $client);

            return PreviewBox::make($executor->describe($request));
        } catch (Throwable $exception) {
            report($exception);

            return PreviewBox::make('Unable to resolve the request. Check the client URL and template configuration.', PreviewBox::TONE_WARNING);
        }
    }

    private static function cronPreview(Get $get): Htmlable
    {
        $expression = (string) $get('cron_expression');
        if (! CronExpression::isValidExpression($expression)) {
            return PreviewBox::make('Enter a valid cron expression to see the next five occurrences.');
        }

        $timezone = $get('timezone') ?: config('opsifin_cron.default_timezone');
        $describer = app(CronDescriber::class);
        $warning = $describer->intervalWarning($expression);
        $lines = [$describer->describe($expression), '', 'Next five occurrences in '.$timezone.':'];

        foreach ((new CronExpression($expression))->getMultipleRunDates(5, Carbon::now($timezone), false, false, $timezone) as $date) {
            $lines[] = '• '.Carbon::instance($date)->setTimezone($timezone)->format('D, d M Y H:i');
        }

        if ($warning) {
            array_splice($lines, 1, 0, 'Warning: '.$warning);
        }

        return PreviewBox::make(implode("\n", $lines), $warning ? PreviewBox::TONE_WARNING : PreviewBox::TONE_INFO);
    }
}
