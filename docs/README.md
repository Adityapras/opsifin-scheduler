# Dokumentasi Opsifin Scheduler

Dokumentasi memakai Markdown sebagai source of truth dan Mermaid untuk diagram.
Struktur ini dapat di-review bersama pull request, dicari dengan Git, dan dirender
langsung oleh GitHub/GitLab. Menu **Help → User guide** juga merender blok Mermaid
menjadi diagram SVG lokal yang responsif; source diagram tidak ditampilkan sebagai
blok kode kepada pengguna.

## Mulai dari sini

| Kebutuhan | Dokumen |
| --- | --- |
| Aturan kerja agent dan perintah repo | [CLAUDE.md](../CLAUDE.md) |
| Memahami aplikasi dan cara menggunakannya | [Panduan Pengguna](user-guide.md) |
| Membaca panduan satu module | [Daftar module](user-guide.md#2-peta-dokumentasi-per-module) |
| Memahami desain teknis end-to-end | [Artifact Teknis](artifact-teknis-opsifin-scheduler.md) |
| Melihat arsitektur ringkas | [Architecture](architecture.md) |
| Menjalankan scheduler di WSL + aaPanel | [Runbook aaPanel](runbook-scheduler-aapanel.md) |
| Menjalankan scheduler di VPS production | [Runbook VPS](runbook-scheduler-vps.md) |
| Mengoperasikan Direct HTTP | [Direct HTTP Operations](direct-http-operations.md) |
| Melihat bukti pengujian | [Direct HTTP Validation](direct-http-validation.md) |
| Menangani operasi umum | [Operations Runbook](operations.md) |
| Melanjutkan pekerjaan lintas sesi | [Current Handoff](handoff.md) |

## Struktur user guide

```text
docs/user-guide.md                         landing, konsep, role, journey
docs/user-guide/01-dashboard-dan-insights.md
docs/user-guide/02-clients.md
docs/user-guide/03-task-templates.md
docs/user-guide/04-schedules.md
docs/user-guide/05-execution-logs.md
docs/user-guide/06-system-dan-observability.md
docs/user-guide/07-operasi-harian.md
```

## Aturan maintenance

1. Perubahan label, field, filter, atau action UI memperbarui file module terkait.
2. Perubahan lifecycle/driver/data model memperbarui Artifact Teknis dan
   Architecture.
3. Perubahan deployment/rollback memperbarui Direct HTTP Operations atau
   Deployment VPS.
4. Jangan menyalin credential, URL ber-secret, `.env`, atau dump database ke docs.
5. Diagram harus memakai Mermaid dan node yang stabil, bukan screenshot runtime.
   Renderer panel dimuat lokal melalui Vite sehingga dokumentasi tidak bergantung
   pada CDN atau layanan diagram eksternal.
6. Link antar-doc menggunakan path relatif agar tetap portabel.
7. Status hasil test dicatat bersama tanggal dan batas pengujian.

## Verifikasi diagram pada panel

Viewer memakai SVG sebagai gambar terisolasi agar CSS Filament tidak mengubah
geometri node/label. Ukuran awal mempertahankan keterbacaan teks; diagram besar
dapat digulir, diperbesar, ditampilkan seluruhnya, atau dibuka pada layar penuh.
Layar penuh langsung menyesuaikan seluruh alur; tombol 100% mengembalikan ukuran
asli. Batas SVG dihitung dari konten yang telah dirender beserta padding, dan
regression test memeriksa setiap node/label agar tidak keluar dari batas gambar.
Jangan memaksakan `width: 100%` pada SVG atau mengganti `fi-not-prose` dengan
`not-prose`: kelas pengecualian typography Filament adalah `fi-not-prose`.

Regression browser berada di `tests/Browser/user-guide.cjs`; petunjuk runtime dan
batas fixture ada di `tests/Browser/README.md`. Pemeriksaan mencakup semua diagram,
desktop/tablet/mobile, light/dark, zoom, fit-all, dan fullscreen. Build/PHP test
saja tidak cukup untuk menyatakan tampilan diagram benar.
