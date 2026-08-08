@php
    $clients = $this->getClients();
    $tasks = $this->getTasks();
    $matrix = $this->getMatrix();
    $canOperate = auth()->user()->canOperate();
@endphp

<x-filament-panels::page>
    <div class="flex flex-wrap items-end gap-4">
        <div class="grow sm:grow-0">
            <label for="clientSearch" class="text-sm font-medium text-gray-700 dark:text-gray-200">Cari client</label>
            <input id="clientSearch" type="search" wire:model.live.debounce.300ms="clientSearch"
                placeholder="kode atau nama"
                class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5" />
        </div>

        <div class="grow sm:grow-0">
            <label for="taskSearch" class="text-sm font-medium text-gray-700 dark:text-gray-200">Cari task</label>
            <input id="taskSearch" type="search" wire:model.live.debounce.300ms="taskSearch" placeholder="key task"
                class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5" />
        </div>

        <label class="flex items-center gap-2 pb-2 text-sm text-gray-700 dark:text-gray-200">
            <input type="checkbox" wire:model.live="onlyEnabled" class="rounded border-gray-300 dark:border-white/10" />
            Hanya tampilkan yang aktif
        </label>

        <div class="ms-auto flex items-center gap-4 pb-2 text-xs text-gray-600 dark:text-gray-400">
            <span class="flex items-center gap-1.5">
                <span class="inline-block size-3 rounded bg-emerald-500"></span> aktif
            </span>
            <span class="flex items-center gap-1.5">
                <span class="inline-block size-3 rounded bg-gray-300 dark:bg-gray-600"></span> ada tapi nonaktif
            </span>
            <span class="flex items-center gap-1.5">
                <span class="inline-block size-3 rounded border border-dashed border-gray-300 dark:border-gray-600"></span>
                belum ada
            </span>
        </div>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <table class="w-max min-w-full border-collapse text-xs">
            <thead>
                <tr>
                    <th class="sticky left-0 z-20 bg-gray-50 px-3 py-2 text-left font-semibold dark:bg-gray-800">
                        Client \ Task
                    </th>
                    @foreach ($tasks as $task)
                        <th class="bg-gray-50 px-1 py-2 align-bottom dark:bg-gray-800">
                            <div class="mx-auto flex flex-col items-center gap-1">
                                <span class="whitespace-nowrap font-medium [writing-mode:vertical-rl] rotate-180"
                                    title="{{ $task->http_method->value }} {{ $task->path_template }}">
                                    {{ $task->key }}
                                </span>
                                @if ($canOperate)
                                    <div class="flex gap-0.5">
                                        <button type="button" wire:click="toggleTaskColumn({{ $task->id }}, true)"
                                            wire:confirm="Aktifkan task {{ $task->key }} untuk semua client?"
                                            class="text-emerald-600 hover:text-emerald-500" title="Aktifkan semua">▲</button>
                                        <button type="button" wire:click="toggleTaskColumn({{ $task->id }}, false)"
                                            wire:confirm="Nonaktifkan task {{ $task->key }} untuk semua client?"
                                            class="text-red-600 hover:text-red-500" title="Nonaktifkan semua">▼</button>
                                    </div>
                                @endif
                            </div>
                        </th>
                    @endforeach
                </tr>
            </thead>

            <tbody>
                @foreach ($clients as $client)
                    <tr class="border-t border-gray-100 dark:border-white/5">
                        <th class="sticky left-0 z-10 whitespace-nowrap bg-white px-3 py-1.5 text-left font-medium dark:bg-gray-900">
                            <div class="flex items-center gap-2">
                                <span @class(['text-gray-400 line-through' => ! $client->is_active])
                                    title="{{ $client->name }} — {{ $client->base_url }}">
                                    {{ $client->code }}
                                </span>
                                @if ($client->needs_review)
                                    <span class="text-amber-500" title="Butuh verifikasi manual">⚠</span>
                                @endif
                                @if ($canOperate)
                                    <span class="ms-auto flex gap-0.5">
                                        <button type="button" wire:click="toggleClientRow({{ $client->id }}, true)"
                                            wire:confirm="Aktifkan semua task untuk {{ $client->code }}?"
                                            class="text-emerald-600 hover:text-emerald-500" title="Aktifkan semua">◀</button>
                                        <button type="button" wire:click="toggleClientRow({{ $client->id }}, false)"
                                            wire:confirm="Nonaktifkan semua task untuk {{ $client->code }}?"
                                            class="text-red-600 hover:text-red-500" title="Nonaktifkan semua">▶</button>
                                    </span>
                                @endif
                            </div>
                        </th>

                        @foreach ($tasks as $task)
                            @php $schedule = $matrix->get($client->id . ':' . $task->id); @endphp
                            <td class="p-0.5 text-center">
                                @if ($schedule)
                                    <button type="button" wire:click="toggle({{ $client->id }}, {{ $task->id }})"
                                        @disabled(! $canOperate)
                                        title="{{ $client->code }} / {{ $task->key }}&#10;{{ $schedule->cron_expression }}&#10;{{ $schedule->is_enabled ? 'aktif' : 'nonaktif' }}"
                                        @class([
                                            'block size-6 rounded transition',
                                            'bg-emerald-500 hover:bg-emerald-400' => $schedule->is_enabled,
                                            'bg-gray-300 hover:bg-gray-400 dark:bg-gray-600 dark:hover:bg-gray-500' => ! $schedule->is_enabled,
                                            'cursor-not-allowed opacity-60' => ! $canOperate,
                                        ])></button>
                                @else
                                    <a href="{{ \App\Filament\Resources\Schedules\ScheduleResource::getUrl('create') }}"
                                        title="Belum ada schedule — klik untuk membuat"
                                        class="block size-6 rounded border border-dashed border-gray-300 hover:border-primary-500 dark:border-gray-600"></a>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p class="text-sm text-gray-600 dark:text-gray-400">
        Menampilkan {{ $clients->count() }} client × {{ $tasks->count() }} task.
        Klik sel untuk menyalakan/mematikan. Perubahan baru berlaku di server setelah
        <a href="{{ \App\Filament\Pages\DeployCrontab::getUrl() }}" class="text-primary-600 underline">deploy crontab</a>.
    </p>
</x-filament-panels::page>
