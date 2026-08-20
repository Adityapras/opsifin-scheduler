<script id="opsifin-appearance-init">
    (() => {
        const palettes = ['opsifin', 'ocean', 'forest', 'sunset']
        const storedPalette = localStorage.getItem('opsifin-palette')

        document.documentElement.dataset.opsifinPalette = palettes.includes(storedPalette)
            ? storedPalette
            : 'opsifin'
    })()
</script>
