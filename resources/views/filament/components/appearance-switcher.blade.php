<div
    x-data="opsifinAppearance(@js(filament()->getDefaultThemeMode()->value))"
    x-on:keydown.escape.window="open = false"
    class="opsifin-appearance-switcher"
>
    <button
        type="button"
        x-on:click="open = ! open"
        x-bind:aria-expanded="open"
        aria-haspopup="dialog"
        aria-label="Appearance settings"
        title="Appearance settings"
        class="opsifin-appearance-trigger"
    >
        <x-filament::icon icon="heroicon-o-swatch" />
    </button>

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
            <span>
                <strong>Appearance</strong>
                <small>Choose a color scheme and palette. Saved on this device.</small>
            </span>

            <button
                type="button"
                x-on:click="open = false"
                aria-label="Close appearance settings"
                class="opsifin-appearance-close"
            >
                <x-filament::icon icon="heroicon-m-x-mark" />
            </button>
        </div>

        <div class="opsifin-appearance-section">
            <span class="opsifin-appearance-label" id="opsifin-scheme-label">Color scheme</span>

            <div class="opsifin-segment" role="group" aria-labelledby="opsifin-scheme-label">
                @foreach ([
                    'light' => ['Light', 'heroicon-o-sun'],
                    'dark' => ['Dark', 'heroicon-o-moon'],
                    'system' => ['System', 'heroicon-o-computer-desktop'],
                ] as $value => [$label, $icon])
                    <button
                        type="button"
                        x-on:click="setTheme('{{ $value }}')"
                        x-bind:aria-pressed="theme === '{{ $value }}'"
                        x-bind:class="{ 'is-active': theme === '{{ $value }}' }"
                        class="opsifin-segment-option"
                    >
                        <x-filament::icon :icon="$icon" />
                        <span>{{ $label }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        <div class="opsifin-appearance-section">
            <span class="opsifin-appearance-label" id="opsifin-palette-label">Color palette</span>

            <div class="opsifin-palette-grid" role="radiogroup" aria-labelledby="opsifin-palette-label">
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
                        <span class="opsifin-palette-radio" aria-hidden="true"></span>
                    </button>
                @endforeach
            </div>
        </div>
    </div>
</div>
