<div
    x-data="{
        open: false,
        palette: 'opsifin',
        theme: 'system',
        init() {
            this.theme = localStorage.getItem('theme') || @js(filament()->getDefaultThemeMode()->value)
            this.setPalette(document.documentElement.dataset.opsifinPalette || 'opsifin')
        },
        setPalette(value) {
            this.palette = value
            localStorage.setItem('opsifin-palette', value)
            document.documentElement.dataset.opsifinPalette = value
        },
        setTheme(value) {
            this.theme = value
            window.dispatchEvent(new CustomEvent('theme-changed', { detail: value }))
        },
    }"
    x-on:keydown.escape.window="open = false"
    class="opsifin-appearance-switcher"
>
    <x-filament::icon-button
        icon="heroicon-o-swatch"
        color="gray"
        label="Appearance settings"
        x-on:click="open = ! open"
        x-bind:aria-expanded="open"
        aria-haspopup="dialog"
        class="opsifin-appearance-trigger"
    />

    <div
        x-cloak
        x-show="open"
        x-on:click.outside="open = false"
        x-transition:enter="opsifin-popover-enter"
        x-transition:enter-start="opsifin-popover-enter-start"
        x-transition:enter-end="opsifin-popover-enter-end"
        x-transition:leave="opsifin-popover-leave"
        x-transition:leave-start="opsifin-popover-leave-start"
        x-transition:leave-end="opsifin-popover-leave-end"
        role="dialog"
        aria-label="Appearance settings"
        class="opsifin-appearance-panel"
    >
        <div class="opsifin-appearance-heading">
            <span class="opsifin-appearance-heading-icon" aria-hidden="true">
                <x-filament::icon icon="heroicon-o-adjustments-horizontal" />
            </span>

            <span class="opsifin-appearance-heading-copy">
                <strong>Appearance</strong>
                <small>Saved on this device</small>
            </span>
        </div>

        <div class="opsifin-appearance-section">
            <span class="opsifin-appearance-label">Mode</span>

            <div class="opsifin-mode-control" role="group" aria-label="Display mode">
                @foreach ([
                    'light' => ['Light', 'heroicon-o-sun'],
                    'dark' => ['Dark', 'heroicon-o-moon'],
                    'system' => ['Auto', 'heroicon-o-computer-desktop'],
                ] as $value => [$label, $icon])
                    <button
                        type="button"
                        x-on:click="setTheme('{{ $value }}')"
                        x-bind:aria-pressed="theme === '{{ $value }}'"
                        x-bind:class="{ 'is-active': theme === '{{ $value }}' }"
                        class="opsifin-mode-option"
                    >
                        <x-filament::icon :icon="$icon" />
                        <span>{{ $label }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        <div class="opsifin-appearance-section">
            <span class="opsifin-appearance-label">Color palette</span>

            <div class="opsifin-palette-grid" role="radiogroup" aria-label="Color palette">
                @foreach ([
                    'opsifin' => 'Opsifin',
                    'ocean' => 'Ocean',
                    'forest' => 'Forest',
                    'sunset' => 'Sunset',
                ] as $value => $label)
                    <button
                        type="button"
                        role="radio"
                        x-bind:aria-checked="palette === '{{ $value }}'"
                        x-on:click="setPalette('{{ $value }}')"
                        x-bind:class="{ 'is-active': palette === '{{ $value }}' }"
                        class="opsifin-palette-option"
                    >
                        <span class="opsifin-palette-swatch is-{{ $value }}" aria-hidden="true"></span>
                        <span>{{ $label }}</span>
                        <x-filament::icon
                            icon="heroicon-m-check"
                            x-show="palette === '{{ $value }}'"
                            x-cloak
                        />
                    </button>
                @endforeach
            </div>
        </div>
    </div>
</div>
