@php
    $classes = match ($tone) {
        'warning' => 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200',
        'info' => 'border-sky-300 bg-sky-50 text-sky-900 dark:border-sky-700 dark:bg-sky-950/40 dark:text-sky-200',
        default => 'border-gray-200 bg-gray-50 text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-400',
    };
@endphp

<pre class="overflow-x-auto whitespace-pre-wrap rounded-lg border p-3 text-xs leading-relaxed {{ $classes }}">{{ $text }}</pre>
