<x-filament-panels::page>
    @vite('resources/js/user-guide.js')
    <div class="grid gap-6 xl:grid-cols-[18rem_minmax(0,1fr)]" data-user-guide>
        <aside>
            <x-filament::section heading="Guide contents">
                <nav class="space-y-5" aria-label="User guide documents">
                    @foreach (collect($this->getDocuments())->groupBy('group', preserveKeys: true) as $group => $documents)
                        <div class="space-y-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ $group }}
                            </p>

                            <div class="space-y-1">
                                @foreach ($documents as $key => $document)
                                    <a
                                        href="{{ $this->getDocumentUrl($key) }}"
                                        @if ($document['path'] === $this->getCurrentDocument()['path']) aria-current="page" @endif
                                        @class([
                                            'block rounded-lg px-3 py-2 text-sm font-medium transition',
                                            'bg-primary-50 text-primary-700 dark:bg-primary-400/10 dark:text-primary-300' => $document['path'] === $this->getCurrentDocument()['path'],
                                            'text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5' => $document['path'] !== $this->getCurrentDocument()['path'],
                                        ])
                                    >
                                        {{ $document['label'] }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </nav>
            </x-filament::section>
        </aside>

        <div class="fi-guide-content">
            <x-filament::section>
                <div class="fi-prose max-w-none overflow-x-auto">
                    {{ $this->getDocumentHtml() }}
                </div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
