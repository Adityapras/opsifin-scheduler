<?php

namespace App\Filament\Resources\Schedules\Schemas;

use App\Enums\LockMode;
use App\Filament\Support\PreviewBox;
use App\Models\Client;
use App\Models\Schedule;
use App\Models\TaskTemplate;
use App\Services\CronDescriber;
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

class ScheduleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Target')
                    ->description('Which client calls which task. The resolved URL is shown below.')
                    ->columns(2)
                    ->schema([
                        Select::make('client_id')
                            ->label('Client')
                            ->relationship('client', 'code')
                            ->getOptionLabelFromRecordUsing(fn (Client $r) => $r->code.' — '.$r->name)
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::syncLockKey($get, $set)),

                        Select::make('task_template_id')
                            ->label('Task')
                            ->relationship('taskTemplate', 'key')
                            ->getOptionLabelFromRecordUsing(fn (TaskTemplate $r) => $r->key.' — '.$r->path_template)
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::syncLockKey($get, $set)),

                        Text::make(fn (Get $get) => self::targetPreview($get))
                            ->columnSpanFull(),
                    ]),

                Section::make('Schedule')
                    ->description('Cron expression and the timezone it is evaluated in.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('cron_expression')
                            ->label('Cron expression')
                            ->placeholder('*/6 * * * *')
                            ->required()
                            ->live(onBlur: true)
                            ->rule(fn () => function (string $attribute, $value, $fail) {
                                if (! CronExpression::isValidExpression((string) $value)) {
                                    $fail('The cron expression is not valid.');
                                }
                            })
                            ->helperText('minute hour day month weekday — for example: */6 * * * *'),

                        Select::make('timezone')
                            ->label('Timezone')
                            ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                            ->searchable()
                            ->required()
                            ->live()
                            ->default(config('opsifin_cron.default_timezone'))
                            ->helperText('Every enabled schedule must use the same timezone — a single cron.d file only has one CRON_TZ.'),

                        Text::make(fn (Get $get) => self::cronPreview($get))
                            ->columnSpanFull(),
                    ]),

                Section::make('Lock')
                    ->description('Every schedule needs a lock — this is what stops jobs from piling up the way they did in the old system.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('lock_key')
                            ->label('Lock key')
                            ->required()
                            ->maxLength(191)
                            ->rule('regex:/^[A-Za-z0-9._-]+$/')
                            ->helperText('Two schedules sharing a lock key never run at the same time.'),

                        Select::make('lock_mode')
                            ->label('Mode')
                            ->options(collect(LockMode::cases())->mapWithKeys(fn ($m) => [$m->value => $m->label()]))
                            ->default(LockMode::Skip->value)
                            ->live()
                            ->required(),

                        TextInput::make('lock_wait_sec')
                            ->label('Wait (seconds)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(3600)
                            ->default(0)
                            ->visible(fn (Get $get) => $get('lock_mode') === LockMode::Wait->value)
                            ->required(fn (Get $get) => $get('lock_mode') === LockMode::Wait->value),
                    ]),

                Section::make('Status')
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_enabled')
                            ->label('Enabled')
                            ->helperText('Only enabled schedules are rendered into the crontab.'),

                        Toggle::make('needs_review')
                            ->label('Needs manual verification'),

                        Textarea::make('review_notes')
                            ->label('Review notes')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Section::make('Legacy origin')
                    ->description('Filled in by the importer. For migration tracing only.')
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        TextInput::make('legacy_pattern')->label('Pattern')->disabled(),
                        TextInput::make('legacy_line_no')->label('Crontab line')->disabled(),
                        Textarea::make('legacy_command')
                            ->label('Original command')
                            ->rows(2)
                            ->disabled()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function syncLockKey(Get $get, Set $set): void
    {
        if (filled($get('lock_key'))) {
            return;
        }

        $client = Client::find($get('client_id'));
        $template = TaskTemplate::find($get('task_template_id'));

        if ($client && $template) {
            $set('lock_key', $client->code.'.'.$template->key);
        }
    }

    private static function targetPreview(Get $get): Htmlable
    {
        $client = Client::find($get('client_id'));
        $template = TaskTemplate::find($get('task_template_id'));

        if (! $client || ! $template) {
            return PreviewBox::make('Pick a client and a task to see the URL that will be called.');
        }

        $schedule = new Schedule([
            'client_id' => $client->id,
            'task_template_id' => $template->id,
        ]);
        $schedule->setRelation('client', $client);
        $schedule->setRelation('taskTemplate', $template);

        $request = $schedule->resolveRequest();
        $override = $schedule->override();

        $text = $request['method']->value.' '.$request['url'];

        if ($override) {
            $text .= "\nThis client has an override — the value above already accounts for it.";
        }

        return PreviewBox::make($text, $override ? PreviewBox::TONE_WARNING : PreviewBox::TONE_INFO);
    }

    private static function cronPreview(Get $get): Htmlable
    {
        $expression = (string) $get('cron_expression');

        if (blank($expression) || ! CronExpression::isValidExpression($expression)) {
            return PreviewBox::make('Enter a valid cron expression to see the upcoming runs.');
        }

        $timezone = $get('timezone') ?: config('opsifin_cron.default_timezone');
        $describer = app(CronDescriber::class);

        $lines = [$describer->describe($expression)];

        if ($warning = $describer->intervalWarning($expression)) {
            $lines[] = '⚠ '.$warning;
        }

        $lines[] = '';
        $lines[] = 'Next 5 runs ('.$timezone.'):';

        $cron = new CronExpression($expression);

        foreach ($cron->getMultipleRunDates(5, Carbon::now($timezone), false, false, $timezone) as $date) {
            $lines[] = '  · '.Carbon::instance($date)->setTimezone($timezone)->format('D, d M Y H:i');
        }

        return PreviewBox::make(
            implode("\n", $lines),
            $warning ? PreviewBox::TONE_WARNING : PreviewBox::TONE_INFO
        );
    }
}
