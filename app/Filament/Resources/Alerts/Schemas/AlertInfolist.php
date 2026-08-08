<?php

namespace App\Filament\Resources\Alerts\Schemas;

use App\Enums\AlertCondition;
use App\Enums\AlertStatus;
use App\Filament\Resources\Runs\RunResource;
use App\Filament\Resources\Schedules\ScheduleResource;
use App\Models\Alert;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AlertInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Alert')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('client.code')->label('Client'),
                        TextEntry::make('taskTemplate.key')->label('Task'),

                        TextEntry::make('condition')
                            ->label('Condition')
                            ->badge()
                            ->formatStateUsing(fn (AlertCondition $state) => $state->label())
                            ->color('gray'),

                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (AlertStatus $state) => $state->label())
                            ->color(fn (AlertStatus $state) => $state->color()),

                        TextEntry::make('title')
                            ->label('Summary')
                            ->columnSpanFull(),

                        TextEntry::make('body')
                            ->label('Detail')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Timeline')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('fired_at')
                            ->label('Fired')
                            ->dateTime('d M Y H:i:s')
                            ->timezone(config('opsifin_cron.default_timezone')),

                        TextEntry::make('acknowledged_at')
                            ->label('Acknowledged')
                            ->dateTime('d M Y H:i:s')
                            ->timezone(config('opsifin_cron.default_timezone'))
                            ->placeholder('—'),

                        TextEntry::make('acknowledgedBy.name')
                            ->label('Acknowledged by')
                            ->placeholder('—'),

                        TextEntry::make('resolved_at')
                            ->label('Resolved')
                            ->dateTime('d M Y H:i:s')
                            ->timezone(config('opsifin_cron.default_timezone'))
                            ->placeholder('—'),
                    ]),

                Section::make('Origin')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('alertRule.name')
                            ->label('Rule')
                            ->placeholder('(rule has been deleted)'),

                        TextEntry::make('schedule_id')
                            ->label('Open schedule')
                            ->placeholder('—')
                            ->url(fn (Alert $record) => $record->schedule_id
                                ? ScheduleResource::getUrl('edit', ['record' => $record->schedule_id])
                                : null),

                        TextEntry::make('run_id')
                            ->label('Open run')
                            ->placeholder('— (missed runs have no run record)')
                            ->url(fn (Alert $record) => $record->run_id
                                ? RunResource::getUrl('view', ['record' => $record->run_id])
                                : null),
                    ]),
            ]);
    }
}
