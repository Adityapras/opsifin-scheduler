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

Form tidak lagi menampilkan kartu **Review & notes**. Nilai `needs_review`,
review notes, dan notes yang sudah ada tetap tersimpan dan kolom **Review** di
tabel tetap tampil.

Default Schedule mengikuti kebijakan Template. Pastikan **Enable immediately**
tidak digunakan tanpa review.

## Credential

Credential disimpan di database sesuai nilai input. Preview dan log melewati
redaction, tetapi backup database tetap sensitif. Jangan hardcode credential
pada Template atau menaruh secret di URL bila dapat memakai header/body.

## Test connection

**Test connection** melakukan GET ke Base URL dengan Authorization Client.
Hasil reachable tidak membuktikan endpoint Task berhasil; lanjutkan dengan
Inspect request dan Run now yang aman.

## Activate, Deactivate, dan provisioning

- Deactivate mencegah eksekusi baru; Run belum start divalidasi ulang.
- Activate membuka master switch; hanya Schedule enabled yang otomatis berjalan.
- Request running tidak dibatalkan.
- **Create missing schedules** membuat assignment auto-assign yang belum ada;
  review sebelum Resume.

## Mengganti credential dengan aman

1. Pause Schedule Client yang berisiko dan pastikan tidak ada Run running.
2. Edit Client dan ganti field yang diperlukan.
3. Test connection, Inspect request, lalu Run now satu endpoint aman.
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
