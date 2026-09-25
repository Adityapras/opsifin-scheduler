# Module Clients

[Kembali ke panduan utama](../user-guide.md)

Menu: **Master data → Clients**. Module ini menyimpan identitas target HTTP,
base URL, timezone, state, dan credential. Perubahan satu Client dapat
memengaruhi seluruh Schedule miliknya.

## Kolom dan filter

Kolom utama: Code, Name, Base URL, Auth, Schedules, Enabled, Review, dan Active.
Filter: Active, Needs review, dan Has enabled schedules.

## Membuat Client

| Field | Penggunaan |
| --- | --- |
| Code | Identifier unik dan stabil |
| Name | Nama yang mudah dibaca |
| Base URL | Scheme + host, tanpa trailing slash |
| Timezone | Zona waktu Client |
| Active | Master switch eksekusi |
| Auth type | Basic, Bearer, atau No auth |
| Username | Basic Auth/placeholder |
| Password/Token | Secret autentikasi |
| Secret key | Secret tambahan untuk header/body |
| Create default schedules | Provisioning dari template auto-assign |

Form tidak lagi menampilkan kartu **Review & notes** dan **Legacy origin**.
Nilai `needs_review`, review notes, notes, dan asal file legacy tetap tersimpan dan kolom **Review** di
tabel tetap tampil.

Default Schedule mengikuti kebijakan Template. Pastikan **Enable immediately**
tidak digunakan tanpa review.

## Credential

Credential disimpan di database sesuai nilai input. Preview dan log melewati
redaction, tetapi backup database tetap sensitif. Jangan hardcode credential
pada Template atau menaruh secret di URL bila dapat memakai header/body.

## Test connection

**Test connection** menguji username dan password Client dengan `GET
{Base URL}/api/remittanceApi` (dapat diubah lewat `CRON_CONNECTION_TEST_PATH`).
Endpoint itu memvalidasi Basic Auth lalu hanya membaca data, jadi aman
diklik kapan saja.

Tombol tersedia di dua tempat:

- menu aksi baris tabel Clients, yang menguji credential **tersimpan**;
- header kartu **Credentials** di halaman Create dan Edit, yang menguji nilai
  yang **sedang diisi di form** tanpa menyimpannya. Pakai ini untuk mengecek
  password baru sebelum klik Save.

| Hasil | Arti | Tindakan |
| --- | --- | --- |
| Credentials valid | HTTP 200, atau 405 (auth lolos, endpoint tanpa GET) | — |
| Credentials rejected | HTTP 401/403 | Perbaiki username/password |
| Check unavailable | HTTP 404 | Periksa Base URL atau versi aplikasi Client |
| Client server error | HTTP 5xx | Periksa server Client, ulangi tes |
| Unexpected response | Status lain, misalnya redirect | Periksa Base URL (http/https) |
| Cannot connect | DNS/TLS/timeout | Periksa Base URL dan jaringan |

**SecretKey tidak ikut diuji.** Header itu hanya divalidasi di jalur POST yang
memproses transaksi. Credential valid juga tidak membuktikan endpoint Task
berhasil; lanjutkan dengan Inspect request dan Run now yang aman.

## Tab Schedules dan Assign jobs

Halaman **Edit Client** terbagi dua tab di atas konten: **Client details**
(form beserta tombol Save, dibuka default) dan **Schedules** (badge = jumlah
Schedule; tooltip = jumlah yang enabled). Tab Schedules berisi job, cron, next
run, hasil terakhir, dan Enabled milik Client tersebut; tab ini tidak memiliki
tombol Save karena setiap aksinya tersimpan langsung.

Centang satu atau beberapa baris di tab Schedules untuk memakai **Bulk actions**
yang sama dengan module Schedules: **Set cron in bulk**, **Resume selected**,
**Pause selected**, dan **Delete selected**. Semuanya meminta konfirmasi. Klik ikon Enabled
untuk Pause/Resume (dengan konfirmasi); menu aksi berisi Edit dan Delete.

**Assign jobs** (header tab Schedules, atau menu aksi baris tabel Clients)
membuat Schedule untuk Client yang sudah ada, misalnya Client lama atau job yang
pernah dihapus:

1. Centang job yang belum dimiliki Client (tersedia Select all). Hanya template
   aktif yang belum di-assign yang ditampilkan.
2. Pilih timing: cron default masing-masing job (timezone Client), atau satu cron
   dan timezone yang sama untuk semua job terpilih.
3. Klik **Assign**. Schedule selalu dibuat **paused**; review lalu Resume.

Job yang sudah dimiliki dilewati. Untuk menambah timing kedua pada job yang sama,
gunakan **New schedule** di module Schedules. Hanya Administrator yang dapat
memakai Assign jobs.

## Activate, Deactivate, dan provisioning

- Deactivate mencegah eksekusi baru; Run belum start divalidasi ulang.
- Activate membuka master switch; hanya Schedule enabled yang otomatis berjalan.
- Request running tidak dibatalkan.
- **Create missing schedules** (bulk) membuat seluruh job aktif dengan "Assign to
  new clients" yang belum ada untuk banyak Client sekaligus, memakai cron default
  job. Hasilnya **selalu paused**, walaupun job diset "Enable immediately". Pakai
  **Assign jobs** bila ingin memilih job. Review sebelum Resume.

## Mengganti credential dengan aman

1. Pause Schedule Client yang berisiko dan pastikan tidak ada Run running.
2. Edit Client, ganti field yang diperlukan, lalu klik **Test connection** di
   kartu Credentials sebelum Save.
3. Save, lalu Inspect request dan Run now satu endpoint aman.
4. Verifikasi hasil dan Resume bertahap.

## Menghapus Client

Menu aksi baris dan halaman Edit memiliki **Delete** (Administrator). Client
hanya dapat dihapus bila tidak memiliki Schedule:

1. Klik **Delete** pada Client. Bila Client masih memiliki Schedule, modal
   menampilkan jumlahnya dan tombol **Open schedules of this client** yang
   membuka tabel Schedules dengan filter Client tersebut.
2. Hapus seluruh Schedule Client itu (lihat
   [Module Schedules](04-schedules.md#menghapus-schedule)).
3. Kembali ke Clients dan klik **Delete** lagi, lalu konfirmasi.

Bulk **Delete selected** melewati Client yang masih memiliki Schedule dan
melaporkan jumlahnya. Riwayat Run tetap disimpan dan setiap penghapusan tercatat
di Audit history. Gunakan Deactivate atau Pause untuk menghentikan eksekusi;
jangan menghapus sebagai kill switch.
