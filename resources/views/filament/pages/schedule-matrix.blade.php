@php
    $taskHeaders = $this->getTaskHeaders();
    $rows = $this->getRows();
    $stats = $this->getStats();
    $canOperate = $this->canOperate();
@endphp

{{-- `row`, `col`, dan `readout` hanya untuk sorotan & keterangan di layar;
     tidak satu pun dikirim ke server. --}}
<div x-data="{ row: null, col: null, readout: '' }"
    x-on:mouseleave="row = null; col = null; readout = ''">
    <x-filament-panels::page>

        {{-- Toolbar --}}
        <div class="flex flex-wrap items-end gap-x-6 gap-y-4 rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <div class="w-full sm:w-52">
                <label for="clientSearch" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">
                    Search client
                </label>
                <input id="clientSearch" type="search" wire:model.live.debounce.300ms="clientSearch"
                    placeholder="code or name"
                    class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5" />
            </div>

            <div class="w-full sm:w-52">
                <label for="taskSearch" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">
                    Search task
                </label>
                <input id="taskSearch" type="search" wire:model.live.debounce.300ms="taskSearch"
                    placeholder="key or name"
                    class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5" />
            </div>

            <label class="flex items-center gap-2 pb-2 text-sm text-gray-700 dark:text-gray-200">
                <input type="checkbox" wire:model.live="onlyEnabled"
                    class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5" />
                Hide empty rows and columns
            </label>

            <div class="ms-auto flex items-center gap-4 pb-2 text-xs text-gray-600 dark:text-gray-400">
                <span class="flex items-center gap-1.5">
                    <span class="inline-block size-3.5 rounded bg-emerald-500"></span> enabled
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="inline-block size-3.5 rounded bg-gray-300 dark:bg-gray-600"></span> disabled
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="inline-block size-1.5 rounded-full bg-gray-300 dark:bg-white/20"></span> no schedule
                </span>
            </div>
        </div>

        <div class="flex flex-col gap-2">
            {{-- Readout: menggantikan tooltip yang akan terpotong container scroll,
                 dan menjawab "ini kolom apa" tanpa harus melebarkan header. --}}
            <div class="flex h-8 items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 font-mono text-xs dark:border-white/10 dark:bg-white/5">
                <span class="shrink-0 text-gray-400 dark:text-gray-500">▸</span>
                <span class="truncate text-gray-700 dark:text-gray-200"
                    x-text="readout || 'Hover a column header or a cell to read it here'"
                    :class="readout ? '' : 'italic text-gray-400 dark:text-gray-500'"></span>
            </div>

            <div class="max-h-[68vh] overflow-auto rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
                <table class="w-max min-w-full border-collapse text-xs">
                    <thead>
                        <tr>
                            <th class="sticky start-0 top-0 z-30 border-b border-e border-gray-200 bg-gray-50 px-3 py-2 text-start text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:border-white/10 dark:bg-gray-800 dark:text-gray-400">
                                Client
                            </th>

                            @foreach ($taskHeaders as $col => $task)
                                <th class="sticky top-0 z-20 border-b border-gray-200 bg-gray-50 px-1 py-1.5 align-bottom transition-colors dark:border-white/10 dark:bg-gray-800"
                                    x-on:mouseenter="col = {{ $col }}; row = null; readout = {{ Js::from($task['readout']) }}"
                                    :class="col === {{ $col }} ? 'bg-gray-100 dark:bg-gray-700' : ''">
                                    <div class="flex w-[4.5rem] flex-col items-center gap-0.5">
                                        <a href="{{ $task['url'] }}"
                                            title="{{ $task['tooltip'] }}"
                                            class="block w-full truncate px-1 text-center text-[11px] font-medium text-gray-700 hover:text-primary-600 dark:text-gray-200 dark:hover:text-primary-400">
                                            {{ $task['key'] }}
                                        </a>

                                        @if ($canOperate)
                                            <x-filament::dropdown teleport placement="bottom-end">
                                                <x-slot name="trigger">
                                                    <button type="button"
                                                        title="Bulk actions for {{ $task['key'] }}"
                                                        class="rounded px-1.5 leading-none text-gray-400 hover:bg-gray-200 hover:text-gray-700 dark:hover:bg-white/10 dark:hover:text-gray-200">
                                                        &vellip;
                                                    </button>
                                                </x-slot>

                                                <x-filament::dropdown.list>
                                                    <x-filament::dropdown.list.item color="success"
                                                        wire:click="toggleTaskColumn({{ $task['id'] }}, true)"
                                                        wire:confirm="Enable {{ $task['key'] }} for every client?">
                                                        <span class="flex items-center gap-2">
                                                            @svg('heroicon-m-check', 'size-5 shrink-0')
                                                            Enable for all clients
                                                        </span>
                                                    </x-filament::dropdown.list.item>

                                                    <x-filament::dropdown.list.item color="danger"
                                                        wire:click="toggleTaskColumn({{ $task['id'] }}, false)"
                                                        wire:confirm="Disable {{ $task['key'] }} for every client?">
                                                        <span class="flex items-center gap-2">
                                                            @svg('heroicon-m-x-mark', 'size-5 shrink-0')
                                                            Disable for all clients
                                                        </span>
                                                    </x-filament::dropdown.list.item>
                                                </x-filament::dropdown.list>
                                            </x-filament::dropdown>
                                        @endif
                                    </div>
                                </th>
                            @endforeach
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($rows as $row => $data)
                            @php $client = $data['client']; @endphp

                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <th class="sticky start-0 z-10 whitespace-nowrap border-e border-gray-200 bg-white px-3 py-1 text-start font-medium transition-colors dark:border-white/10 dark:bg-gray-900"
                                    :class="row === {{ $row }} ? 'bg-gray-100/70 dark:bg-white/[0.05]' : ''"
                                    x-on:mouseenter="row = {{ $row }}; col = null; readout = {{ Js::from($client->code.'  ·  '.$client->name.'  ·  '.$client->base_url.'  ·  '.$data['enabled_count'].' enabled') }}">
                                    <div class="flex w-48 items-center gap-1.5">
                                        <a href="{{ \App\Filament\Resources\Clients\ClientResource::getUrl('edit', ['record' => $client->getKey()]) }}"
                                            title="{{ $client->name }}&#10;{{ $client->base_url }}"
                                            @class([
                                                'truncate text-[13px] hover:text-primary-600 dark:hover:text-primary-400',
                                                'text-gray-400 line-through' => ! $client->is_active,
                                            ])>
                                            {{ $client->code }}
                                        </a>

                                        @if ($client->needs_review)
                                            <span class="shrink-0 text-amber-500" title="Needs manual verification">⚠</span>
                                        @endif

                                        <span class="ms-auto flex shrink-0 items-center gap-1.5 ps-2">
                                            <span @class([
                                                'tabular-nums text-[11px]',
                                                'font-medium text-emerald-600 dark:text-emerald-400' => $data['enabled_count'] > 0,
                                                'text-gray-300 dark:text-gray-600' => $data['enabled_count'] === 0,
                                            ])>{{ $data['enabled_count'] }}</span>

                                            @if ($canOperate)
                                                <x-filament::dropdown teleport placement="bottom-start">
                                                    <x-slot name="trigger">
                                                        <button type="button"
                                                            title="Bulk actions for {{ $client->code }}"
                                                            class="rounded px-1.5 leading-none text-gray-400 hover:bg-gray-200 hover:text-gray-700 dark:hover:bg-white/10 dark:hover:text-gray-200">
                                                            &vellip;
                                                        </button>
                                                    </x-slot>

                                                    <x-filament::dropdown.list>
                                                        <x-filament::dropdown.list.item color="success"
                                                            wire:click="toggleClientRow({{ $client->id }}, true)"
                                                            wire:confirm="Enable every task for {{ $client->code }}?">
                                                            <span class="flex items-center gap-2">
                                                                @svg('heroicon-m-check', 'size-5 shrink-0')
                                                                Enable all tasks
                                                            </span>
                                                        </x-filament::dropdown.list.item>

                                                        <x-filament::dropdown.list.item color="danger"
                                                            wire:click="toggleClientRow({{ $client->id }}, false)"
                                                            wire:confirm="Disable every task for {{ $client->code }}?">
                                                            <span class="flex items-center gap-2">
                                                                @svg('heroicon-m-x-mark', 'size-5 shrink-0')
                                                                Disable all tasks
                                                            </span>
                                                        </x-filament::dropdown.list.item>
                                                    </x-filament::dropdown.list>
                                                </x-filament::dropdown>
                                            @endif
                                        </span>
                                    </div>
                                </th>

                                @foreach ($data['cells'] as $col => $cell)
                                    @include('filament.components.matrix-cell', [
                                        'cell' => $cell,
                                        'clientId' => $client->id,
                                        'row' => $row,
                                        'col' => $col,
                                        'canOperate' => $canOperate,
                                    ])
                                @endforeach
                            </tr>
                        @endforeach

                        @if ($rows === [])
                            <tr>
                                <td colspan="{{ count($taskHeaders) + 1 }}"
                                    class="px-3 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                                    No client matches the current filters.
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>

        <p class="text-sm text-gray-600 dark:text-gray-400">
            Showing {{ $stats['clients'] }} clients × {{ $stats['tasks'] }} tasks
            · {{ $stats['enabled'] }} schedules enabled overall.
            Click a cell to enable or disable it. Changes only reach the server after a
            <a href="{{ \App\Filament\Pages\DeployCrontab::getUrl() }}" class="text-primary-600 underline">crontab deploy</a>.
        </p>

    </x-filament-panels::page>
</div>
