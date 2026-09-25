@php
    $rows = $getState() ?? [];
    $showBefore = collect($rows)->contains(fn ($row) => $row['before'] !== null);
    $showAfter = collect($rows)->contains(fn ($row) => $row['after'] !== null);
@endphp

@if ($rows === [])
    <p class="text-sm text-gray-500 dark:text-gray-400">No field values were recorded for this entry.</p>
@else
    <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
        <table class="w-full table-auto divide-y divide-gray-200 text-start text-sm dark:divide-white/5">
            <thead class="bg-gray-50 dark:bg-white/5">
                <tr>
                    <th class="px-3 py-2 text-start font-semibold text-gray-950 dark:text-white">Field</th>
                    @if ($showBefore)
                        <th class="px-3 py-2 text-start font-semibold text-gray-950 dark:text-white">Before</th>
                    @endif
                    @if ($showAfter)
                        <th class="px-3 py-2 text-start font-semibold text-gray-950 dark:text-white">After</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                @foreach ($rows as $row)
                    <tr @class(['bg-warning-50 dark:bg-warning-400/10' => $row['changed']])>
                        <td class="whitespace-nowrap px-3 py-2 align-top font-mono text-xs font-medium text-gray-700 dark:text-gray-200">{{ $row['field'] }}</td>
                        @if ($showBefore)
                            <td @class([
                                'px-3 py-2 align-top font-mono text-xs break-all whitespace-pre-wrap',
                                'text-danger-700 dark:text-danger-300' => $row['changed'],
                                'text-gray-700 dark:text-gray-300' => ! $row['changed'],
                            ])>{{ $row['before'] ?? '—' }}</td>
                        @endif
                        @if ($showAfter)
                            <td @class([
                                'px-3 py-2 align-top font-mono text-xs break-all whitespace-pre-wrap',
                                'text-success-700 font-semibold dark:text-success-300' => $row['changed'],
                                'text-gray-700 dark:text-gray-300' => ! $row['changed'],
                            ])>{{ $row['after'] ?? '—' }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Credentials and secrets are stored as [redacted] and never shown here.</p>
@endif
