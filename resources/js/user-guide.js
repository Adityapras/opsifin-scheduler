let mermaidPromise
let sequence = 0
let queue = Promise.resolve()
const viewers = new WeakMap()
const currentTheme = () => document.documentElement.classList.contains('dark') ? 'dark' : 'light'

const button = (label, title, action) => {
    const el = document.createElement('button')
    el.type = 'button'
    el.textContent = label
    el.title = title
    el.setAttribute('aria-label', title)
    el.addEventListener('click', action)
    return el
}

const createViewer = (title, expanded = false) => {
    const element = document.createElement('div')
    element.className = 'fi-guide-viewer'
    const toolbar = document.createElement('div')
    toolbar.className = 'fi-guide-toolbar'
    const caption = document.createElement('span')
    caption.className = 'fi-guide-caption'
    caption.textContent = title
    const controls = document.createElement('div')
    controls.className = 'fi-guide-controls'
    const viewport = document.createElement('div')
    viewport.className = 'fi-guide-viewport'
    viewport.tabIndex = 0
    viewport.setAttribute('role', 'region')
    viewport.setAttribute('aria-label', title + ' — area diagram dapat digulir')
    const picture = document.createElement('img')
    picture.className = 'fi-guide-image'
    picture.alt = title
    const status = document.createElement('span')
    status.className = 'fi-guide-scale'
    status.setAttribute('aria-live', 'polite')
    const hint = document.createElement('div')
    hint.className = 'fi-guide-hint'
    hint.textContent = 'Geser untuk melihat bagian lainnya. Gunakan Lihat semua untuk menampilkan seluruh alur.'
    hint.hidden = true
    let width = 0, height = 0, scale = 1, mode = expanded ? 'fit' : 'readable'
    const resize = (value) => {
        if (!width) return
        scale = Math.max(0.1, Math.min(2, value))
        picture.style.width = Math.round(width * scale) + 'px'
        picture.style.height = Math.round(height * scale) + 'px'
        status.textContent = Math.round(scale * 100) + '%'
        hint.hidden = viewport.scrollWidth <= viewport.clientWidth + 1 && viewport.scrollHeight <= viewport.clientHeight + 1
    }
    // Preserve readable text; wide diagrams scroll instead of shrinking to dots.
    const fit = () => {
        const ratio = Math.min(1, (viewport.clientWidth - 40) / width)
        resize(mode === 'fit' ? Math.min(ratio, (parseFloat(getComputedStyle(viewport).maxHeight) - 40) / height) : Math.max(0.85, ratio))
    }
    controls.append(
        button('−', 'Perkecil diagram', () => { mode = 'manual'; resize(scale - 0.15) }),
        status,
        button('+', 'Perbesar diagram', () => { mode = 'manual'; resize(scale + 0.15) }),
        button('Lihat semua', 'Tampilkan seluruh diagram di panel', () => { mode = 'fit'; fit() }),
        button('100%', 'Kembalikan diagram ke ukuran asli', () => { mode = 'manual'; resize(1) }),
    )
    if (!expanded) controls.append(button('Layar penuh', 'Buka diagram dalam layar penuh', () => {
        const dialog = document.createElement('dialog')
        dialog.className = 'fi-guide-dialog fi-not-prose'
        dialog.setAttribute('aria-label', title)
        const viewer = createViewer(title, true)
        const close = button('Tutup', 'Tutup layar penuh', () => dialog.close())
        viewer.controls.append(close)
        dialog.append(viewer.element)
        document.body.append(dialog)
        dialog.addEventListener('close', () => { viewer.destroy(); dialog.remove() }, {once: true})
        dialog.showModal()
        viewer.setImage(picture.src, width, height)
        close.focus()
    }))
    toolbar.append(caption, controls)
    viewport.append(picture)
    element.append(toolbar, hint, viewport)
    // Our own image resizing changes viewport height. Only react to width,
    // and defer writes until the next frame to avoid ResizeObserver loops.
    let frame = 0, observedWidth = -1
    const scheduleFit = () => {
        cancelAnimationFrame(frame)
        frame = requestAnimationFrame(() => { if (mode !== 'manual') fit() })
    }
    const observer = new ResizeObserver(([entry]) => {
        const nextWidth = entry.borderBoxSize[0]?.inlineSize ?? viewport.clientWidth
        if (nextWidth === observedWidth) return
        observedWidth = nextWidth
        scheduleFit()
    })
    observer.observe(viewport)
    window.addEventListener('resize', scheduleFit)
    return {
        element,
        controls,
        setImage(src, w, h) { width = w; height = h; picture.src = src; fit() },
        destroy() {
            observer.disconnect()
            cancelAnimationFrame(frame)
            window.removeEventListener('resize', scheduleFit)
        },
    }
}

const prepare = (root) => {
    root.querySelectorAll('pre > code.language-mermaid').forEach((code) => {
        const pre = code.closest('pre')
        const source = code.textContent.trim()
        if (!source || !pre) return
        let heading = pre.previousElementSibling
        while (heading && !/^H[1-6]$/.test(heading.tagName)) heading = heading.previousElementSibling
        const figure = document.createElement('figure')
        // Filament uses fi-not-prose (not Tailwind Typography's not-prose).
        figure.className = 'fi-user-guide-diagram fi-not-prose'
        figure.setAttribute('wire:ignore', '')
        figure.dataset.mermaidSource = source
        figure.dataset.diagramTitle = heading?.textContent || 'Diagram alur aplikasi'
        figure.setAttribute('aria-busy', 'true')
        figure.textContent = 'Memuat diagram…'
        pre.replaceWith(figure)
    })
}

const render = async () => {
    const root = document.querySelector('[data-user-guide]')
    if (!root) return
    prepare(root)
    const figures = [...root.querySelectorAll('.fi-user-guide-diagram')]
    if (!figures.length) return
    const theme = currentTheme()
    try {
        mermaidPromise ??= import('mermaid').then(({default: mermaid}) => mermaid)
        const mermaid = await mermaidPromise
        mermaid.initialize({
            startOnLoad: false,
            securityLevel: 'strict',
            theme: theme === 'dark' ? 'dark' : 'default',
            // Same local font during measurement and inside the isolated SVG.
            fontFamily: 'Arial, sans-serif',
            htmlLabels: false,
            themeVariables: {fontFamily: 'Arial, sans-serif', fontSize: '16px'},
            themeCSS: '.edgeLabel rect { opacity: 1 !important; fill: ' + (theme === 'dark' ? '#1e293b' : '#f8fafc') + ' !important; }',
            flowchart: {htmlLabels: false, curve: 'linear', nodeSpacing: 32, rankSpacing: 44, padding: 16, useMaxWidth: false},
            sequence: {useMaxWidth: false, wrap: true, actorMargin: 45, messageMargin: 36},
        })
        for (const figure of figures) {
            if (!figure.isConnected || figure.dataset.renderedTheme === theme) continue
            const stage = document.createElement('div')
            stage.className = 'fi-guide-render-stage fi-not-prose'
            document.body.append(stage)
            try {
                const {svg} = await mermaid.render('opsifin-guide-' + ++sequence, figure.dataset.mermaidSource, stage)
                // Measure the final SVG, including transformed nodes and labels.
                // The layout engine's initial viewBox may omit content on some renderers.
                stage.innerHTML = svg
                const rendered = stage.querySelector('svg')
                await document.fonts.ready
                const bounds = rendered.getBBox()
                const padding = 16
                const width = Math.ceil(bounds.width + padding * 2)
                const height = Math.ceil(bounds.height + padding * 2)
                if (!(width > 0 && height > 0)) throw new Error('Invalid SVG dimensions')
                rendered.setAttribute('viewBox', [bounds.x - padding, bounds.y - padding, width, height].join(' '))
                rendered.setAttribute('width', width)
                rendered.setAttribute('height', height)
                rendered.setAttribute('preserveAspectRatio', 'xMidYMid meet')
                rendered.style.removeProperty('max-width')
                // SVG-as-image isolates all diagram geometry from the panel CSS.
                const src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(new XMLSerializer().serializeToString(rendered))
                const probe = new Image()
                probe.src = src
                await probe.decode()
                if (!figure.isConnected) continue
                viewers.get(figure)?.destroy()
                const viewer = createViewer(figure.dataset.diagramTitle)
                viewers.set(figure, viewer)
                figure.replaceChildren(viewer.element)
                viewer.setImage(src, width, height)
                figure.dataset.renderedTheme = theme
                figure.setAttribute('aria-busy', 'false')
            } catch (error) {
                figure.textContent = 'Diagram gagal dimuat. Muat ulang halaman untuk mencoba kembali.'
                figure.setAttribute('aria-busy', 'false')
                console.error('Unable to render guide diagram', error)
            } finally {
                stage.remove()
            }
        }
    } catch (error) {
        for (const figure of figures) {
            figure.textContent = 'Diagram gagal dimuat. Muat ulang halaman untuk mencoba kembali.'
            figure.setAttribute('aria-busy', 'false')
        }
        console.error('Unable to load guide renderer', error)
    }
}

let timer
const scheduleRender = () => {
    clearTimeout(timer)
    timer = setTimeout(() => {
        // Serialize theme/navigation events so Mermaid is never reconfigured mid-render.
        queue = queue.then(render).catch(console.error)
    }, 50)
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scheduleRender, {once: true})
else scheduleRender()
document.addEventListener('livewire:navigated', scheduleRender)
window.addEventListener('theme-changed', scheduleRender)
new MutationObserver(scheduleRender).observe(document.documentElement, {attributes: true, attributeFilter: ['class']})
