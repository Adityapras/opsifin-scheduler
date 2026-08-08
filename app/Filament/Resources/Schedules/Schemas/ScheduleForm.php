<?php

namespace App\Filament\Resources\Schedules\Schemas;

use App\Enums\LockMode;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

class ScheduleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Target')
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

                        Text::make(fn (Get $get) => new HtmlString(self::targetPreview($get)))
                            ->columnSpanFull(),
                    ]),

                Section::make('Jadwal')
                    ->columns(2)
                    ->schema([
                        TextInput::make('cron_expression')
                            ->label('Ekspresi cron')
                            ->placeholder('*/6 * * * *')
                            ->required()
                            ->live(onBlur: true)
                            ->rule(fn () => function (string $attribute, $value, $fail) {
                                if (! CronExpression::isValidExpression((string) $value)) {
                                    $fail('Ekspresi cron tidak valid.');
                                }
                            })
                            ->helperText('menit jam tanggal bulan hari — contoh: */6 * * * *'),

                        Select::make('timezone')
                            ->label('Timezone')
                            ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                            ->searchable()
                            ->required()
                            ->live()
                            ->default(config('opsifin_cron.default_timezone'))
                            ->helperText('Seluruh schedule aktif harus memakai timezone yang sama — satu file cron.d hanya punya satu CRON_TZ.'),

                        Text::make(fn (Get $get) => new HtmlString(self::cronPreview($get)))
                            ->columnSpanFull(),
                    ]),

                Section::make('Lock')
                    ->description('Setiap schedule wajib punya lock — inilah yang mencegah job menumpuk seperti di sistem lama.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('lock_key')
                            ->label('Lock key')
                            ->required()
                            ->maxLength(191)
                            ->rule('regex:/^[A-Za-z0-9._-]+$/')
                            ->helperText('Dua schedule dengan lock key sama tidak akan pernah berjalan bersamaan.'),

                        Select::make('lock_mode')
                            ->label('Mode')
                            ->options(collect(LockMode::cases())->mapWithKeys(fn ($m) => [$m->value => $m->label()]))
                            ->default(LockMode::Skip->value)
                            ->live()
                            ->required(),

                        TextInput::make('lock_wait_sec')
                            ->label('Tunggu (detik)')
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
                            ->label('Aktif')
                            ->helperText('Hanya schedule aktif yang ikut di-render ke crontab.'),

                        Toggle::make('needs_review')
                            ->label('Butuh verifikasi manual'),

                        Textarea::make('review_notes')
                            ->label('Catatan review')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Section::make('Asal data legacy')
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        TextInput::make('legacy_pattern')->label('Pola')->disabled(),
                        TextInput::make('legacy_line_no')->label('Baris crontab')->disabled(),
                        Textarea::make('legacy_command')
                            ->label('Perintah asli')
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

    private static function targetPreview(Get $get): string
    {
        $client = Client::find($get('client_id'));
        $template = TaskTemplate::find($get('task_template_id'));

        if (! $client || ! $template) {
            return self::box('Pilih client dan task untuk melihat URL yang akan dipanggil.', 'gray');
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
            $text .= "\nClient ini punya override — nilai di atas sudah memperhitungkannya.";
        }

        return self::box($text, $override ? 'warning' : 'info');
    }

    private static function cronPreview(Get $get): string
    {
        $expression = (string) $get('cron_expression');

        if (blank($expression) || ! CronExpression::isValidExpression($expression)) {
            return self::box('Masukkan ekspresi cron yang valid untuk melihat jadwal berikutnya.', 'gray');
        }

        $timezone = $get('timezone') ?: config('opsifin_cron.default_timezone');
        $describer = app(CronDescriber::class);

        $lines = [$describer->describe($expression)];

        if ($warning = $describer->intervalWarning($expression)) {
            $lines[] = '⚠ '.$warning;
        }

        $lines[] = '';
        $lines[] = '5 jadwal berikutnya ('.$timezone.'):';

        $cron = new CronExpression($expression);

        foreach ($cron->getMultipleRunDates(5, Carbon::now($timezone), false, false, $timezone) as $date) {
            $lines[] = '  · '.Carbon::instance($date)->setTimezone($timezone)->format('D, d M Y H:i');
        }

        return self::box(implode("\n", $lines), $warning ? 'warning' : 'info');
    }

    private static function box(string $text, string $tone): string
    {
        $classes = match ($tone) {
            'warning' => 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200',
            'info' => 'border-sky-300 bg-sky-50 text-sky-900 dark:border-sky-700 dark:bg-sky-950/40 dark:text-sky-200',
            default => 'border-gray-300 bg-gray-50 text-gray-600 dark:border-gray-700 dark:bg-white/5 dark:text-gray-400',
        };

        return '<pre class="whitespace-pre-wrap rounded-lg border p-3 text-xs '.$classes.'">'.e($text).'</pre>';
    }
}
