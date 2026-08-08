<?php

namespace App\Filament\Resources\Runs\Schemas;

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Filament\Resources\Schedules\ScheduleResource;
use App\Models\Run;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Summary')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('client.code')->label('Client'),
                        TextEntry::make('taskTemplate.key')->label('Task'),

                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (RunStatus $state) => $state->label())
                            ->color(fn (RunStatus $state) => match ($state) {
                                RunStatus::Success => 'success',
                                RunStatus::Failed, RunStatus::Timeout => 'danger',
                                RunStatus::SkippedLock => 'warning',
                                RunStatus::Running => 'info',
                                default => 'gray',
                            }),

                        TextEntry::make('trigger')
                            ->label('Trigger')
                            ->badge()
                            ->color('gray')
                            ->formatStateUsing(fn (RunTrigger $state) => $state->label()),

                        TextEntry::make('started_at')
                            ->label('Started')
                            ->dateTime('d M Y H:i:s')
                            ->timezone(config('opsifin_cron.default_timezone')),

                        TextEntry::make('finished_at')
                            ->label('Finished')
                            ->dateTime('d M Y H:i:s')
                            ->timezone(config('opsifin_cron.default_timezone'))
                            ->placeholder('—'),

                        TextEntry::make('duration_ms')
                            ->label('Duration')
                            ->formatStateUsing(fn (?int $state) => $state === null ? '—' : number_format($state).' ms'),

                        TextEntry::make('host')->label('Host')->placeholder('—'),
                    ]),

                Section::make('Request')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('request_method')->label('Method')->badge()->placeholder('—'),

                        TextEntry::make('request_url')
                            ->label('URL')
                            ->copyable()
                            ->placeholder('—')
                            ->columnSpan(3),
                    ]),

                Section::make('Response')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('http_status')
                            ->label('HTTP status')
                            ->badge()
                            ->placeholder('—')
                            ->color(fn (?int $state) => $state === null ? 'gray' : ($state < 400 ? 'success' : 'danger')),

                        TextEntry::make('error_message')
                            ->label('Error message')
                            ->placeholder('—')
                            ->columnSpan(3),

                        TextEntry::make('response_excerpt')
                            ->label('Body (truncated)')
                            ->placeholder('—')
                            ->fontFamily('mono')
                            ->columnSpanFull(),
                    ]),

                Section::make('Schedule')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('schedule.cron_expression')
                            ->label('Cron expression')
                            ->fontFamily('mono')
                            ->placeholder('(schedule has been deleted)'),

                        TextEntry::make('schedule.lock_key')->label('Lock key')->placeholder('—'),

                        TextEntry::make('schedule_id')
                            ->label('Open schedule')
                            ->placeholder('—')
                            ->url(fn (Run $record) => $record->schedule_id
                                ? ScheduleResource::getUrl('edit', ['record' => $record->schedule_id])
                                : null),
                    ]),
            ]);
    }
}
