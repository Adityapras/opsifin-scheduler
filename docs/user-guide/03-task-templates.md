# Module Task Templates

[Kembali ke panduan utama](../user-guide.md)

Menu: **Master data → Task templates**. Template mendefinisikan request HTTP
canonical yang dapat dipakai banyak Client.

## Field utama

| Field | Penggunaan |
| --- | --- |
| Key | Identifier `snake_case` stabil |
| Name/Description | Nama dan tujuan bisnis |
| Executor | Saat ini HTTP request |
| Template active | Master switch seluruh assignment |
| Method/Path | Request dan path relatif terhadap Base URL |
| JSON body | Body request |
| Additional headers | Header tambahan |
| Connect/Request timeout | Batas koneksi dan seluruh request |
| Assign to new clients | Provisioning Client baru |
| Default cron/timezone policy | Timing assignment baru |
| Enable immediately | State awal assignment baru |
| Prevent overlapping runs | Overlap policy awal |

Form tersusun dua kolom: **Job template** dan **HTTP request** di kolom lebar,
**Default schedule** dan **Timeouts** di kolom samping. Kartu Migration trace
tidak ditampilkan lagi; datanya tetap tersimpan.

Default hanya berlaku ketika assignment dibuat; perubahan default tidak menimpa
Schedule yang sudah ada.

## Placeholder

| Placeholder | Nilai runtime |
| --- | --- |
| `{{client.code}}` | Code Client |
| `{{client.username}}` | Username |
| `{{client.secret}}` | Password/token |
| `{{client.password}}` | Alias kompatibilitas |
| `{{client.secret_key}}` | Secret key |
| `{{run.scheduled_for}}` | Occurrence ISO-8601 UTC |

Gunakan placeholder pada path, headers, atau body. Authorization standar dibuat
dari Client; jangan hardcode.

## Assignment

- **Assign all active clients**: seluruh Client aktif yang belum mempunyai
  assignment; biarkan state awal paused untuk rollout aman.
- **Assign selected clients**: pilot atau scope bisnis tertentu.
- **Remove from selected clients**: hapus assignment yang dipilih setelah
  memastikan tidak ada Run aktif.

Assignment idempotent dan tidak mengubah Schedule lama diam-diam.

## Checklist template baru

1. Dokumentasikan tujuan, side effect, owner, dan idempotency.
2. Gunakan satu template untuk request bisnis yang sama.
3. Pilih timeout berdasarkan perilaku endpoint.
4. Assign ke satu Client pilot dalam state paused.
5. Inspect request, Run now satu kali, dan review efek bisnis.

Menonaktifkan Template mencegah eksekusi baru tanpa menghapus Schedule/histori.
Template hanya dapat dihapus bila tidak memiliki Schedule.

