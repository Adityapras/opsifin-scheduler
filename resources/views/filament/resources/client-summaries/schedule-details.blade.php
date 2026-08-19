<div class="space-y-6">
    <x-filament::section
        heading="Configured jobs"
        description="Every timing currently assigned to this client."
        icon="heroicon-o-clock"
        icon-color="primary"
    >
        <div class="divide-y divide-gray-200 dark:divide-white/10">
            @forelse ($client->schedules->sortBy(fn ($schedule) => ($schedule->taskTemplate?->key ?? '').'|'.$schedule->cron_expression) as $schedule)
                <div class="grid gap-3 py-4 first:pt-0 last:pb-0 md:grid-cols-[minmax(0,1fr)_auto_auto] md:items-center">
                    <div class="min-w-0">
                        <p class="font-semibold text-gray-950 dark:text-white">
                            {{ $schedule->taskTemplate?->key ?? 'Deleted task' }}
                        </p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $schedule->taskTemplate?->name ?? 'The original task template no longer exists.' }}
                        </p>
                    </div>

                    <code class="rounded-lg bg-gray-100 px-3 py-1.5 text-sm text-gray-700 dark:bg-white/5 dark:text-gray-200">
                        {{ $schedule->cron_expression }} · {{ $schedule->timezone }}
                    </code>

                    <x-filament::badge :color="$schedule->is_enabled ? 'success' : 'gray'">
                        {{ $schedule->is_enabled ? 'Enabled' : 'Paused' }}
                    </x-filament::badge>
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">No job has been assigned to this client.</p>
            @endforelse
        </div>
    </x-filament::section>

    <x-filament::section
        heading="Missing active jobs"
        description="Task templates in the active catalog without an assignment for this client."
        icon="heroicon-o-exclamation-triangle"
        :icon-color="$missingJobs->isEmpty() ? 'success' : 'warning'"
    >
        <div class="flex flex-wrap gap-2">
            @forelse ($missingJobs as $job)
                <x-filament::badge color="danger">{{ $job }}</x-filament::badge>
            @empty
                <x-filament::badge color="success">Coverage complete</x-filament::badge>
            @endforelse
        </div>
    </x-filament::section>
</div>
