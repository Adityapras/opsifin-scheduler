@php
    use App\Filament\Pages\ScheduleMatrix;

    $isMissing = $cell['state'] === ScheduleMatrix::STATE_MISSING;

    // Sel kosong sengaja dibuat sangat sunyi — titik kecil, bukan kotak
    // putus-putus — supaya yang aktif dan nonaktif menonjol saat dipindai.
    $swatch = match ($cell['state']) {
        ScheduleMatrix::STATE_ENABLED => 'size-5 rounded bg-emerald-500 group-hover/cell:bg-emerald-400',
        ScheduleMatrix::STATE_DISABLED => 'size-5 rounded bg-gray-300 group-hover/cell:bg-gray-400 dark:bg-gray-600 dark:group-hover/cell:bg-gray-500',
        default => 'size-1.5 rounded-full bg-gray-200 group-hover/cell:size-5 group-hover/cell:rounded group-hover/cell:bg-primary-200 dark:bg-white/10 dark:group-hover/cell:bg-primary-400/40',
    };

    $target = 'group/cell flex size-7 items-center justify-center rounded transition-colors hover:bg-gray-200/60 dark:hover:bg-white/10';
@endphp

<td class="p-px"
    x-on:mouseenter="row = {{ $row }}; col = {{ $col }}; readout = {{ Js::from($cell['readout']) }}"
    :class="(row === {{ $row }} || col === {{ $col }}) ? 'bg-gray-100/70 dark:bg-white/[0.05]' : ''">
    @if ($isMissing || ! $canOperate)
        <a href="{{ $cell['url'] }}" title="{{ $cell['tooltip'] }}" class="{{ $target }}">
            <span class="block transition-all {{ $swatch }}"></span>
        </a>
    @else
        <button type="button"
            wire:click="toggle({{ $clientId }}, {{ $cell['task_id'] }})"
            wire:loading.attr="disabled"
            title="{{ $cell['tooltip'] }}"
            class="{{ $target }}">
            <span class="block transition-all {{ $swatch }}"></span>
        </button>
    @endif
</td>
