@php
    $problems = $this->getProblems();
    $diff = $this->getDiff();
    $backups = $this->getBackups();
    $added = collect($diff)->where('type', 'added')->count();
    $removed = count($diff) - $added;
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Target</x-slot>

        <dl class="grid gap-4 sm:grid-cols-3">
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">File</dt>
                <dd class="font-mono text-sm">{{ $this->getTargetPath() }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">Enabled schedules</dt>
                <dd class="text-sm font-semibold">{{ number_format($this->getEnabledCount()) }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">Changes</dt>
                <dd class="text-sm font-semibold">
                    <span class="text-emerald-600">+{{ $added }}</span>
                    <span class="text-red-600">−{{ $removed }}</span> lines
                </dd>
            </div>
        </dl>
    </x-filament::section>

    @if ($problems)
        <x-filament::section>
            <x-slot name="heading">
                <span class="text-danger-600">Validation failed — {{ count($problems) }} schedules</span>
            </x-slot>
            <x-slot name="description">Deploying is blocked until every problem below is resolved.</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-1 pe-4">Client</th>
                            <th class="py-1 pe-4">Task</th>
                            <th class="py-1">Problem</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($problems as $problem)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="py-1 pe-4 font-medium">
                                    <a class="text-primary-600 underline"
                                        href="{{ \App\Filament\Resources\Schedules\ScheduleResource::getUrl('edit', ['record' => $problem['schedule']]) }}">
                                        {{ $problem['schedule']->client->code }}
                                    </a>
                                </td>
                                <td class="py-1 pe-4">{{ $problem['schedule']->taskTemplate->key }}</td>
                                <td class="py-1">{{ $problem['problem'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Validation</x-slot>
            <p class="text-sm text-emerald-600">All enabled schedules pass validation.</p>
        </x-filament::section>
    @endif

    <x-filament::section collapsible>
        <x-slot name="heading">Diff</x-slot>
        <x-slot name="description">The current file contents compared with the freshly rendered output.</x-slot>

        @if (! $diff)
            <p class="text-sm text-gray-500 dark:text-gray-400">No changes — the file on the server already matches the database.</p>
        @else
            <pre class="max-h-96 overflow-auto rounded-lg bg-gray-950/5 p-3 text-xs leading-relaxed dark:bg-white/5">@foreach ($diff as $entry)<span @class([
                'block',
                'text-emerald-700 dark:text-emerald-400' => $entry['type'] === 'added',
                'text-red-700 dark:text-red-400' => $entry['type'] === 'removed',
            ])>{{ $entry['type'] === 'added' ? '+' : '-' }} {{ $entry['line'] }}</span>@endforeach</pre>
        @endif
    </x-filament::section>

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">Full file preview</x-slot>

        <pre class="max-h-[32rem] overflow-auto rounded-lg bg-gray-950/5 p-3 text-xs dark:bg-white/5">{{ $this->getPreview() }}</pre>
    </x-filament::section>

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">Backups ({{ count($backups) }} most recent)</x-slot>

        @if (! $backups)
            <p class="text-sm text-gray-500 dark:text-gray-400">No backups yet — the file is backed up automatically on the first deploy.</p>
        @else
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-1 pe-4">File</th>
                        <th class="py-1 pe-4">Size</th>
                        <th class="py-1">Time</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($backups as $backup)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="py-1 pe-4 font-mono text-xs">{{ $backup['name'] }}</td>
                            <td class="py-1 pe-4">{{ number_format($backup['size']) }} B</td>
                            <td class="py-1">{{ $backup['time'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
