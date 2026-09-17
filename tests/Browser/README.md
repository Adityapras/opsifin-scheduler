# User guide: browser regression

This check renders the real Laravel/Filament User Guide HTML against the current
production Vite assets. The fixture forces SQLite `:memory:`, array sessions/cache,
and disables telemetry before bootstrapping. It never enables real schedules or
sends HTTP jobs. Browser network requests are fulfilled from local assets; only
the unrelated notification polling response is stubbed.

Prerequisites: PHP with the application's extensions, a Playwright Node package,
and its installed Chromium headless browser. Playwright is a test-only runtime;
it is not required by the deployed application. `PLAYWRIGHT_MODULE` can point to
an existing Playwright or Playwright Core package directory. `PHP_BINARY` selects
the PHP executable. Playwright's usual `PLAYWRIGHT_BROWSERS_PATH` is supported.
`CHROMIUM_EXECUTABLE` optionally selects an installed Chrome executable.

```sh
npm run build
PHP_BINARY=/path/to/php PLAYWRIGHT_MODULE=/path/to/playwright node tests/Browser/user-guide.cjs
```

An optional positional index selects one viewport batch: `0` = desktop, `1` =
desktop at device scale 125%, `2` = tablet, `3` = mobile. Device scale is not
browser zoom; these cases keep browser zoom at 100%.

For native Windows Chrome with PHP in WSL, first export `overview`, `technical`,
and `schedules` with `export-user-guide.php <document> <fixture-directory>`.
Run this test with Windows Node and set `GUIDE_FIXTURE_DIR` to that directory's
Windows/WSL UNC path, `PLAYWRIGHT_MODULE` to the Windows package, and
`CHROMIUM_EXECUTABLE` to Chrome. Pre-exported HTML skips the PHP subprocess;
screenshots are written into a unique child directory of the fixture directory.
Re-export fixtures after changing documentation, views, or the production build.

The check covers all 14 diagrams (overview, technical artifact, schedules) at
1536, 1024, and 390 CSS pixels, plus desktop at device scale 125%, in light and
dark themes: 24 page/theme scenarios and 112 diagram renders. It checks image decoding, SVG text and dimensions,
readable initial scale, bounded height, page overflow, zoom, fit-all, fullscreen,
and Escape dismissal. Every node, cluster and text label is checked against the
SVG viewBox, and every diagram is opened in fullscreen on desktop (not just the
first diagram). Fullscreen must initially fit the whole diagram. Runtime errors
fail the check.
Zero-size empty Mermaid text placeholders are excluded from containment checks;
visible nodes and labels are not. Reduced-motion mode is enabled to exercise
the application's global animation stylesheet as well as viewer controls.

Screenshots, SVGs and metrics are written into a unique temporary directory
printed at completion. Review screenshots as well as assertions: passing PHP
tests or a Vite build alone does not verify diagram appearance. External web
fonts are blocked in this fixture; diagram measurement and display both use a
local system font to avoid a web-font loading race.
