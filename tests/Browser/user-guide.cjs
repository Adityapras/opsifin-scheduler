// Visual regression against actual Laravel/Filament HTML and the production Vite build.
// No connection to the application database or to real HTTP job endpoints.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const output = fs.mkdtempSync(path.join(process.env.GUIDE_FIXTURE_DIR || os.tmpdir(), 'opsifin-guide-visual-'));
const fixture = process.env.GUIDE_FIXTURE_DIR || output;
const documents = { overview: 2, technical: 11, schedules: 1 };

(async () => {
    if (!process.env.GUIDE_FIXTURE_DIR) {
        for (const document of Object.keys(documents)) {
            execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'export-user-guide.php'), document, fixture], {cwd: root});
        }
    }
    const browser = await chromium.launch({headless: true, executablePath: process.env.CHROMIUM_EXECUTABLE});
    const results = [];
    try {
        const cases = [[1536, 1], [1536, 1.25], [1024, 1], [390, 1]];
        const selected = process.argv[2] === undefined ? cases : [cases[Number(process.argv[2])]];
        for (const [width, deviceScaleFactor] of selected) {
            const page = await browser.newPage({viewport: {width, height: 1000}, deviceScaleFactor, reducedMotion: 'reduce'});
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.route('**/*', async route => {
                const url = new URL(route.request().url());
                if (url.hostname !== 'guide.test') return route.abort();
                if (url.pathname === '/admin/user-guide') {
                    const document = url.searchParams.get('document') || 'overview';
                    return route.fulfill({contentType: 'text/html', path: path.join(fixture, 'opsifin-guide-' + document + '.html')});
                }
                // Notification lazy-load/polling is outside this static visual fixture.
                if (url.pathname.endsWith('/update')) {
                    const data = route.request().postDataJSON();
                    return route.fulfill({json: {components: data.components.map(c => ({snapshot: c.snapshot, effects: {}})), assets: []}});
                }
                const file = url.pathname.includes('/livewire') && url.pathname.endsWith('.js')
                    ? path.join(root, 'vendor/livewire/livewire/dist/livewire.js')
                    : path.join(root, 'public', url.pathname);
                if (fs.existsSync(file) && fs.statSync(file).isFile()) return route.fulfill({path: file});
                return route.fulfill({status: 404, body: ''});
            });
            for (const [document, expected] of Object.entries(documents)) {
                await page.goto('http://guide.test/admin/user-guide?document=' + document);
                for (const theme of ['light', 'dark']) {
                    await page.evaluate(theme => document.documentElement.classList.toggle('dark', theme === 'dark'), theme);
                    await page.waitForFunction(({expected, theme}) => {
                        const figures = [...document.querySelectorAll('.fi-user-guide-diagram')];
                        return figures.length === expected && figures.every(f => f.dataset.renderedTheme === theme && f.querySelector('img')?.naturalWidth > 0);
                    }, {expected, theme}, {timeout: 30000});
                    assert.deepEqual(errors, [], 'No browser runtime errors');
                    const clipped = await page.locator('.fi-user-guide-diagram img').evaluateAll(images => images.flatMap((img, index) => {
                        const stage = document.createElement('div');
                        stage.className = 'fi-guide-render-stage fi-not-prose';
                        stage.innerHTML = decodeURIComponent(img.src.slice(img.src.indexOf(',') + 1));
                        document.body.append(stage);
                        const svg = stage.querySelector('svg');
                        const vb = svg.viewBox.baseVal;
                        const inverse = svg.getScreenCTM().inverse();
                        const outside = [...svg.querySelectorAll('.node, .cluster, text')].filter(n => !n.closest('defs, marker')).flatMap(n => {
                            const b = n.getBBox();
                            // Mermaid state diagrams include empty text placeholders at (0, 0).
                            if (n.tagName === 'text' && !n.textContent.trim() && !b.width && !b.height) return [];
                            const m = inverse.multiply(n.getScreenCTM());
                            const corners = [[b.x, b.y], [b.x + b.width, b.y], [b.x, b.y + b.height], [b.x + b.width, b.y + b.height]];
                            return corners.some(([x, y]) => {
                                const p = new DOMPoint(x, y).matrixTransform(m);
                                return p.x < vb.x - 1 || p.y < vb.y - 1 || p.x > vb.x + vb.width + 1 || p.y > vb.y + vb.height + 1;
                            }) ? [{index, text: n.textContent, tag: n.tagName, box: {x: b.x, y: b.y, width: b.width, height: b.height}}] : [];
                        });
                        stage.remove();
                        return outside;
                    }));
                    assert.deepEqual(clipped, [], 'Every node and text label must fit inside the SVG viewBox');
                    const metrics = await page.locator('.fi-user-guide-diagram').evaluateAll(figures => figures.map(f => {
                        const img = f.querySelector('img');
                        const svg = new DOMParser().parseFromString(decodeURIComponent(img.src.split(',').slice(1).join(',')), 'image/svg+xml');
                        return {width: f.clientWidth, height: f.clientHeight, naturalWidth: img.naturalWidth, naturalHeight: img.naturalHeight,
                            scale: img.width / img.naturalWidth, text: svg.querySelectorAll('text').length};
                    }));
                    for (const metric of metrics) {
                        assert.ok(metric.width <= width, 'Diagram card stays inside page');
                        assert.ok(metric.height < 850, 'Tall diagrams have a bounded viewport');
                        assert.ok(metric.scale >= 0.84 && metric.scale <= 1.01, 'Default scale keeps text readable without enlargement');
                        assert.ok(metric.text > 0, 'SVG contains native readable text');
                        assert.ok(metric.naturalWidth < 6000 && metric.naturalHeight < 6000, 'No exploded SVG geometry');
                    }
                    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'No page-level horizontal overflow');
                    const first = page.locator('.fi-user-guide-diagram').first();
                    if (width === 1536) {
                        for (let i = 0; i < expected; i++) {
                            const figure = page.locator('.fi-user-guide-diagram').nth(i);
                            await figure.screenshot({path: path.join(output, document + '-diagram-' + i + '-' + theme + '.png')});
                            const src = await figure.locator('img').getAttribute('src');
                            fs.writeFileSync(path.join(output, document + '-diagram-' + i + '-' + theme + '.svg'), decodeURIComponent(src.slice(src.indexOf(',') + 1)));
                        }
                    }
                    await first.scrollIntoViewIfNeeded();
                    await page.screenshot({path: path.join(output, document + '-' + width + '-' + theme + '.png')});
                    for (let i = 0; i < (width === 1536 ? expected : 1); i++) {
                        const target = page.locator('.fi-user-guide-diagram').nth(i);
                        const oldWidth = await target.locator('img').evaluate(img => img.width);
                        await target.getByRole('button', {name: 'Perbesar diagram', exact: true}).click();
                        assert.ok(await target.locator('img').evaluate((img, old) => img.width > old, oldWidth), 'Zoom changes visible image size');
                        await target.getByRole('button', {name: 'Tampilkan seluruh diagram di panel'}).click();
                        assert.ok(await target.locator('.fi-guide-viewport').evaluate(v => v.scrollWidth <= v.clientWidth + 1 && v.scrollHeight <= v.clientHeight + 1), 'Fit-all shows the complete diagram');
                        await target.getByRole('button', {name: 'Buka diagram dalam layar penuh'}).click();
                        const dialog = page.getByRole('dialog');
                        assert.equal(await dialog.count(), 1);
                        await dialog.locator('img').evaluate(img => img.decode());
                        assert.ok(await dialog.locator('.fi-guide-viewport').evaluate(v => v.scrollWidth <= v.clientWidth + 1 && v.scrollHeight <= v.clientHeight + 1), 'Fullscreen starts with the complete diagram visible');
                        if (width === 1536) await page.screenshot({path: path.join(output, document + '-fullscreen-' + i + '-' + deviceScaleFactor + '-' + theme + '.png')});
                        await dialog.getByRole('button', {name: 'Kembalikan diagram ke ukuran asli'}).click();
                        assert.ok(await dialog.locator('img').evaluate(img => Math.abs(img.width - img.naturalWidth) <= 1), 'Fullscreen 100% preserves native image size');
                        await page.keyboard.press('Escape');
                        assert.equal(await dialog.count(), 0, 'Escape closes fullscreen and removes viewer');
                    }
                    assert.deepEqual(errors, [], 'No runtime errors after viewer interactions');
                    results.push({document, width, deviceScaleFactor, theme, diagrams: expected, metrics});
                    console.log('Passed: ' + [document, width, deviceScaleFactor, theme].join(' / '));
                }
            }
            await page.close();
        }
        console.log(JSON.stringify({passed: results.length, diagramRenders: results.reduce((n, r) => n + r.diagrams, 0), output}, null, 2));
        fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify(results, null, 2));
    } finally {
        await browser.close();
    }
})().catch(error => {console.error(error); process.exitCode = 1});
