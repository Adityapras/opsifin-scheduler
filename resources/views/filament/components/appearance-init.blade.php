<script id="opsifin-appearance-init">
    (() => {
        const palettes = ['opsifin', 'ocean', 'forest', 'sunset']
        const themes = ['light', 'dark', 'system']
        const storedPalette = localStorage.getItem('opsifin-palette')

        document.documentElement.dataset.opsifinPalette = palettes.includes(storedPalette)
            ? storedPalette
            : 'opsifin'

        window.opsifinAppearance = (defaultTheme = 'system') => ({
            open: false,
            palette: document.documentElement.dataset.opsifinPalette,
            theme: themes.includes(localStorage.getItem('theme'))
                ? localStorage.getItem('theme')
                : defaultTheme,

            setPalette(value) {
                const nextPalette = palettes.includes(value) ? value : 'opsifin'

                this.palette = nextPalette
                localStorage.setItem('opsifin-palette', nextPalette)
                document.documentElement.dataset.opsifinPalette = nextPalette
            },

            setTheme(value) {
                if (! themes.includes(value)) {
                    return
                }

                this.theme = value
                window.dispatchEvent(new CustomEvent('theme-changed', { detail: value }))
            },
        })
    })()
</script>
