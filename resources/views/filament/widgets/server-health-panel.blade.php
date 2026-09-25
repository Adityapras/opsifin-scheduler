@php
    $colors = ['ok' => 'success', 'warning' => 'warning', 'critical' => 'danger', 'unknown' => 'gray'];
    $labels = ['ok' => 'Healthy', 'warning' => 'Warning', 'critical' => 'Critical', 'unknown' => 'Unknown'];
    $icons = ['ok' => 'heroicon-o-check-circle', 'warning' => 'heroicon-o-exclamation-triangle', 'critical' => 'heroicon-o-x-circle', 'unknown' => 'heroicon-o-question-mark-circle'];
@endphp

<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-server-stack" :icon-color="$colors[$overall]">
        <x-slot name="heading">Server health</x-slot>
        <x-slot name="description">Checked {{ $checkedAt }} · refreshes every 30 seconds</x-slot>
        <x-slot name="afterHeader">
            <x-filament::badge :color="$colors[$overall]" :icon="$icons[$overall]" size="lg">
                {{ $labels[$overall] }}
            </x-filament::badge>
        </x-slot>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @foreach ($checks as $check)
                <div class="flex items-start gap-3 rounded-lg p-3 ring-1 ring-gray-950/5 dark:ring-white/10" wire:key="health-{{ $check['key'] }}">
                    <x-filament::icon :icon="$icons[$check['status']]" @class([
                        'mt-0.5 h-5 w-5 shrink-0',
                        'text-success-600 dark:text-success-400' => $check['status'] === 'ok',
                        'text-warning-600 dark:text-warning-400' => $check['status'] === 'warning',
                        'text-danger-600 dark:text-danger-400' => $check['status'] === 'critical',
                        'text-gray-400' => $check['status'] === 'unknown',
                    ]) />
                    <div class="min-w-0">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $check['label'] }}</p>
                        <p class="text-base font-semibold text-gray-950 dark:text-white">{{ $check['value'] }}</p>
                        @if ($check['detail'])
                            <p class="truncate text-xs text-gray-500 dark:text-gray-400" title="{{ $check['detail'] }}">{{ $check['detail'] }}</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        @if ($problems !== [])
            <div class="mt-4 space-y-2">
                <p class="text-sm font-semibold text-gray-950 dark:text-white">Mitigation steps</p>
                @foreach ($problems as $problem)
                    <div @class([
                        'rounded-lg p-3 text-sm',
                        'bg-danger-50 text-danger-700 dark:bg-danger-400/10 dark:text-danger-300' => $problem['status'] === 'critical',
                        'bg-warning-50 text-warning-700 dark:bg-warning-400/10 dark:text-warning-300' => $problem['status'] === 'warning',
                    ]) wire:key="mitigation-{{ $problem['key'] }}">
                        <span class="font-semibold">{{ $problem['label'] }}:</span>
                        {{ $problem['mitigation'] }}
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
