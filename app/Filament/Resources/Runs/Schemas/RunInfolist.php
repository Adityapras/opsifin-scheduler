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
        return $schema->components([
            Section::make('Execution')
                ->description('One queued HTTP execution. Failed executions may be retried manually.')
                ->columns(4)
                ->schema([
                    TextEntry::make('client.code')->label('Client')->placeholder('Deleted'),
                    TextEntry::make('taskTemplate.key')->label('Job')->placeholder('Deleted'),
                    TextEntry::make('status')->badge()
                        ->formatStateUsing(fn (RunStatus $state) => $state->label())
                        ->color(fn (RunStatus $state) => $state->color()),
                    TextEntry::make('trigger')->badge()->color('gray')
                        ->formatStateUsing(fn (RunTrigger $state) => $state->label()),
                    TextEntry::make('scheduled_for')->label('Scheduled for')->dateTime('d M Y H:i:s')->timezone(config('opsifin_cron.default_timezone')),
                    TextEntry::make('queued_at')->label('Queued')->dateTime('d M Y H:i:s')->timezone(config('opsifin_cron.default_timezone'))->placeholder('—'),
                    TextEntry::make('started_at')->label('Started')->dateTime('d M Y H:i:s')->timezone(config('opsifin_cron.default_timezone'))->placeholder('—'),
                    TextEntry::make('finished_at')->label('Finished')->dateTime('d M Y H:i:s')->timezone(config('opsifin_cron.default_timezone'))->placeholder('—'),
                    TextEntry::make('duration_ms')->label('Duration')->formatStateUsing(fn (?int $state) => $state === null ? '—' : number_format($state).' ms'),
                    TextEntry::make('http_status')->label('HTTP')->badge()->placeholder('—'),
                    TextEntry::make('worker')->label('Worker')->placeholder('—')->columnSpan(2),
                    TextEntry::make('response_excerpt')->label('Response excerpt')->fontFamily('mono')->placeholder('—')->columnSpan(2),
                    TextEntry::make('error_message')->label('Error')->placeholder('—')->columnSpan(2),
                ]),

            Section::make('Links')
                ->columns(3)
                ->schema([
                    TextEntry::make('schedule.cron_expression')->label('Schedule')->fontFamily('mono')->placeholder('Deleted'),
                    TextEntry::make('schedule_id')->label('Open schedule')->placeholder('—')
                        ->url(fn (Run $record) => $record->schedule_id ? ScheduleResource::getUrl('edit', ['record' => $record->schedule_id]) : null),
                    TextEntry::make('source_run_id')->label('Source run')->placeholder('—'),
                ]),
        ]);
    }
}
