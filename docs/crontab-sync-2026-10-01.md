# Sinkronisasi Schedule dari `crontab.txt` (2026-10-01)

Dokumen ini memetakan `crontab.txt` di root repo ke tabel `schedules`,
membandingkannya dengan isi database development, dan menjadi pendamping
query [`sql/crontab-sync-2026-10-01.sql`](sql/crontab-sync-2026-10-01.sql).
Query **belum dijalankan**; Aditya mengecek dan menjalankannya sendiri.

Snapshot DB diambil 2026-10-01 dari container `mysql` (`opsifin_cron`).
Bila data berubah sebelum query dijalankan, guard `cron_expression` pada
setiap `UPDATE` membuat row itu tidak tertimpa (0 affected row).

## Ringkasan

| Item | Jumlah |
| --- | ---: |
| Schedule di DB (33 client × 20 Task Template) | 660 |
| Schedule yang punya baris di `crontab.txt` | 385 |
| ↳ sudah sama, tidak di-update | 353 |
| ↳ berbeda, di-update | 32 |
| &nbsp;&nbsp;&nbsp;↳ di antaranya `cron_expression` berubah | 25 |
| &nbsp;&nbsp;&nbsp;↳ schedule enabled, dipisah sebagai opsional | 1 |
| Schedule tanpa baris di `crontab.txt` (dibiarkan as is) | 275 |
| Baris `crontab.txt` yang tidak bisa dipetakan | 48 |
| Pasangan client+task dengan lebih dari satu baris | 31 |

## Aturan mapping

1. **Client.** Baris `/home/ubuntu/cron/<folder>/<script>.sh` dipetakan lewat
   `clients.legacy_script_dir`; baris `gateway.sh <kode> <task>` lewat
   `clients.legacy_config_file` (`configs/<kode>.conf`).

   | Sumber di crontab | `clients.code` |
   | --- | --- |
   | folder `mncTravel` | `mnc_travel` |
   | folder `hitstravel` | `hits_travel` |
   | gateway `gnj` | `gn` |
   | gateway `mna` | `aladin` |
   | gateway `mtt` | `mitra` |
   | gateway `mnc` | `mnc_travel` |
   | gateway `mytours` | `mytour` |
   | gateway `kiaxxx` | `kia` (bukan `kiaxxxharmoni`; config `kiaharmoni.conf`) |
   | gateway `qa2` | `qa1` (client `qa1` bernama "QA2" memakai `configs/qa2.conf`) |
   | lainnya | sama dengan nama folder/kode |

2. **Task Template.** Nama script dipetakan lewat
   `task_templates.legacy_script_names` + alias importer
   (`recuring`→`recurring`, `postInvoice`→`post_invoice_to_opsigo`,
   `post_log_remittance`→`request_bca_api`,
   `repost_remittance`→`repost_bca_api`,
   `updateStatusAutoPrintBilling`→`update_status_print_billing`). Task
   gateway memakai `task_templates.key` langsung.
3. **Satu baris per client+task.** Bila ada beberapa baris: baris aktif menang
   atas baris komentar; sesama komentar dipilih yang `cron_expression`-nya sudah
   sama dengan DB, jika tidak ada dipilih baris terakhir (perilaku importer lama).
4. **Field yang di-update:** `cron_expression`, `legacy_pattern`,
   `legacy_line_no` (nomor baris di `crontab.txt`), `legacy_command`,
   `legacy_was_commented`, `legacy_had_flock`, `legacy_lock_file`, `updated_at`.
   `legacy_line_no` hanya ikut berubah pada row yang memang berbeda.
5. **Tidak disentuh:** `is_enabled` (seluruh schedule tetap paused kecuali
   #2959; baris aktif di crontab **tidak** otomatis meng-enable), `timezone`
   (tetap `Asia/Jakarta`), `prevent_overlap`, `next_run_at`, dan 275
   schedule yang tidak punya baris di `crontab.txt`.

## Perubahan yang akan diterapkan

Kolom status: `aktif` = baris crontab tidak dikomentari, `komentar` = diawali `#`.
Ini hanya metadata `legacy_was_commented`, bukan `is_enabled`.

| ID | Client | Task | Cron DB | Cron TXT | Status DB → TXT | Baris | Field berubah |
| ---: | --- | --- | --- | --- | --- | ---: | --- |
| 3156 | aneka | repost | `*/2 * * * *` | `*/2 3-23,0-1 * * *` | komentar → aktif | 340 | `cron_expression`, `legacy_was_commented` |
| 3347 | excape | auto_summary | `*/5 * * * *` | `*/5 * * * *` | manual → komentar | 530 | `legacy_pattern`, `legacy_command`, `legacy_was_commented` |
| 3348 | excape | billing_file | `*/5 * * * *` | `*/10 * * * *` | manual → komentar | 526 | `cron_expression`, `legacy_pattern`, `legacy_command`, `legacy_was_commented` |
| 3349 | excape | clear_inactive_temp_issued | `*/5 * * * *` | `2 5 * * *` | manual → aktif | 524 | `cron_expression`, `legacy_pattern`, `legacy_command` |
| 3350 | excape | clear_shared_folder_opsigo | `*/5 * * * *` | `3 5 * * *` | manual → aktif | 525 | `cron_expression`, `legacy_pattern`, `legacy_command` |
| 3351 | excape | e_invoice | `*/5 * * * *` | `*/5 22-23,0-3 * * *` | manual → aktif | 521 | `cron_expression`, `legacy_pattern`, `legacy_command`, `legacy_had_flock`, `legacy_lock_file` |
| 3353 | excape | kill_process_timeout | `*/5 * * * *` | `*/1 * * * *` | manual → komentar | 516 | `cron_expression`, `legacy_pattern`, `legacy_command`, `legacy_was_commented` |
| 3354 | excape | post_invoice_to_opsigo | `*/5 * * * *` | `*/5 * * * *` | manual → komentar | 520 | `legacy_pattern`, `legacy_command`, `legacy_was_commented` |
| 3355 | excape | recurring | `*/5 * * * *` | `0 3 * * *` | manual → komentar | 529 | `cron_expression`, `legacy_pattern`, `legacy_command`, `legacy_was_commented` |
| 3356 | excape | repost | `*/5 * * * *` | `*/5 * * * *` | manual → aktif | 514 | `legacy_pattern`, `legacy_command` |
| 3357 | excape | repost_bca_api | `*/5 * * * *` | `10 5 * * *` | manual → komentar | 518 | `cron_expression`, `legacy_pattern`, `legacy_command`, `legacy_was_commented` |
| 3359 | excape | request_bca_api | `*/5 * * * *` | `0 5 * * *` | manual → komentar | 517 | `cron_expression`, `legacy_pattern`, `legacy_command`, `legacy_was_commented` |
| 3360 | excape | update_balance_trx | `*/5 * * * *` | `*/7 * * * *` | manual → aktif | 515 | `cron_expression`, `legacy_pattern`, `legacy_command` |
| 3361 | excape | update_expired_user | `*/5 * * * *` | `1 5 * * *` | manual → aktif | 523 | `cron_expression`, `legacy_pattern`, `legacy_command` |
| 3362 | excape | update_print_no_hardcopy | `*/5 * * * *` | `*/2 * * * *` | manual → aktif | 522 | `cron_expression`, `legacy_pattern`, `legacy_command` |
| 3056 | gardi | recurring | `0 3 * * *` | `0 1 * * *` | komentar → aktif | 249 | `cron_expression`, `legacy_pattern`, `legacy_command`, `legacy_was_commented` |
| 3453 | gn | auto_summary | `*/10 1,4-5 * * *` | `*/5 * * * *` | manual → komentar | 19 | `cron_expression`, `legacy_pattern`, `legacy_command`, `legacy_was_commented` |
| 2866 | gn | recurring | `0 3 * * *` | `0 3 * * *` | komentar → aktif | 18 | `legacy_pattern`, `legacy_command`, `legacy_was_commented` |
| 3459 | gns | auto_summary | `*/5 * * * *` | `*/5 * * * *` | manual → komentar | 433 | `legacy_pattern`, `legacy_command`, `legacy_was_commented` |
| 3326 | hits_travel | auto_mail_sp | `*/5 * * * *` | `0 21 * * *` | manual → komentar | 335 | `cron_expression`, `legacy_pattern`, `legacy_command`, `legacy_was_commented` |
| 3329 | hits_travel | clear_inactive_temp_issued | `*/5 * * * *` | `0 0 * * *` | manual → komentar | 337 | `cron_expression`, `legacy_pattern`, `legacy_command`, `legacy_was_commented` |
| 3331 | hits_travel | e_invoice | `*/5 * * * *` | `*/5 22-23,0-3 * * *` | manual → komentar | 336 | `cron_expression`, `legacy_pattern`, `legacy_command`, `legacy_was_commented` |
| 3333 | hits_travel | kill_process_timeout | `*/5 * * * *` | `*/1 6-18 * * *` | manual → komentar | 330 | `cron_expression`, `legacy_pattern`, `legacy_command`, `legacy_was_commented` |
| 3334 | hits_travel | post_invoice_to_opsigo | `*/5 * * * *` | `*/5 3-23,0-1 * * *` | manual → komentar | 331 | `cron_expression`, `legacy_pattern`, `legacy_command`, `legacy_was_commented` |
| 3336 | hits_travel | repost | `*/5 * * * *` | `*/2 * * * *` | manual → komentar | 328 | `cron_expression`, `legacy_pattern`, `legacy_command`, `legacy_was_commented` |
| 3340 | hits_travel | update_balance_trx | `*/5 * * * *` | `*/7 3-23,0-1 * * *` | manual → komentar | 329 | `cron_expression`, `legacy_pattern`, `legacy_command`, `legacy_was_commented` |
| 3341 | hits_travel | update_expired_user | `*/5 * * * *` | `0 21 * * *` | manual → komentar | 334 | `cron_expression`, `legacy_pattern`, `legacy_command`, `legacy_was_commented` |
| 3342 | hits_travel | update_print_no_hardcopy | `*/5 * * * *` | `*/2 6-18 * * *` | manual → komentar | 333 | `cron_expression`, `legacy_pattern`, `legacy_command`, `legacy_was_commented` |
| 3475 | jontru | auto_summary | `*/5 * * * *` | `*/5 * * * *` | manual → komentar | 413 | `legacy_pattern`, `legacy_command`, `legacy_was_commented` |
| 3501 | konverm | auto_billing | `*/5 * * * *` | `*/10 1,4-5 * * *` | manual → komentar | 373 | `cron_expression`, `legacy_pattern`, `legacy_command`, `legacy_was_commented`, `legacy_had_flock`, `legacy_lock_file` |
| 3549 | privela | auto_summary | `*/5 * * * *` | `*/5 * * * *` | manual → komentar | 511 | `legacy_pattern`, `legacy_command`, `legacy_was_commented` |
| 2959 **(enabled, opsional)** | qa2 | repost | `1-59/10 * * * *` | `1-59/5 * * * *` | aktif → aktif | 119 | `cron_expression` |

Catatan per kelompok:

- **excape** (14 row) dan **hits_travel** (9 row) sebelumnya dibuat manual dengan
  default `*/5 * * * *` tanpa metadata legacy. Sekarang mendapat cron dan
  command dari `crontab.txt`.
- **`recurring`** untuk gardi kini berasal dari baris aktif `gardi/recuring.sh`
  (`0 1 * * *`), menggantikan baris gateway yang dikomentari (`0 3 * * *`).
  Untuk gn, baris gateway `gnj recurring` kini aktif di `crontab.txt`.
- **#2959 qa2/repost** sedang enabled dan mengirim trafik nyata ke QA2. Di SQL
  ia ditaruh sebagai blok opsional yang dikomentari karena `1-59/5` menggandakan
  trafik dibanding `1-59/10` yang dipasang sengaja pada 25 Sep 2026.

## Komparasi lengkap

Seluruh schedule yang punya padanan di `crontab.txt`. Kolom **Hasil**: `SAMA`
berarti tidak ada update, `UPDATE` berarti masuk SQL.

### agi

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 3085 | auto_billing | `*/10 1,4-5 * * *` | `*/10 1,4-5 * * *` | aktif | 287 | SAMA |
| 3077 | auto_mail_sp | `0 21 * * *` | `0 21 * * *` | komentar | 279 | SAMA |
| 3081 | billing_file | `*/10 * * * *` | `*/10 * * * *` | komentar | 283 | SAMA |
| 3079 | clear_inactive_temp_issued | `1 5 * * *` | `1 5 * * *` | aktif | 281 | SAMA |
| 3080 | clear_shared_folder_opsigo | `2 5 * * *` | `2 5 * * *` | aktif | 282 | SAMA |
| 3078 | e_invoice | `*/5 22-23,0-3 * * *` | `*/5 22-23,0-3 * * *` | aktif | 280 | SAMA |
| 3073 | kill_process_timeout | `*/1 6-18 * * *` | `*/1 6-18 * * *` | komentar | 274 | SAMA |
| 3074 | post_invoice_to_opsigo | `*/5 3-23,0-1 * * *` | `*/5 3-23,0-1 * * *` | komentar | 275 | SAMA |
| 3084 | recurring | `0 3 * * *` | `0 3 * * *` | komentar | 286 | SAMA |
| 3071 | repost | `*/2 3-23,0-1 * * *` | `*/2 3-23,0-1 * * *` | aktif | 272 | SAMA |
| 3072 | update_balance_trx | `*/7 3-23,0-1 * * *` | `*/7 3-23,0-1 * * *` | komentar | 273 | SAMA |
| 3076 | update_expired_user | `0 21 * * *` | `0 21 * * *` | komentar | 278 | SAMA |
| 3075 | update_print_no_hardcopy | `*/2 6-18 * * *` | `*/2 6-18 * * *` | aktif | 277 | SAMA |
| 3083 | update_status_print_billing | `0 0 * * *` | `0 0 * * *` | komentar | 285 | SAMA |

### aladin

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 2874 | auto_mail_credit_limit | `0 11,15,18 * * *` | `0 11,15,18 * * *` | aktif | 27 | SAMA |
| 2877 | billing_file | `*/10 * * * *` | `*/10 * * * *` | komentar | 30 | SAMA |
| 2875 | clear_inactive_temp_issued | `1 5 * * *` | `1 5 * * *` | aktif | 28 | SAMA |
| 2876 | clear_shared_folder_opsigo | `2 5 * * *` | `2 5 * * *` | aktif | 29 | SAMA |
| 2871 | e_invoice | `*/5 21-23,0-1 * * *` | `*/5 21-23,0-1 * * *` | aktif | 24 | SAMA |
| 2880 | recurring | `0 3 * * *` | `0 3 * * *` | komentar | 33 | SAMA |
| 2869 | repost | `*/5 3-23,0-1 * * *` | `*/5 3-23,0-1 * * *` | aktif | 22 | SAMA |
| 2870 | update_balance_trx | `*/7 * * * *` | `*/7 * * * *` | aktif | 23 | SAMA |
| 2873 | update_expired_user | `0 21 * * *` | `0 21 * * *` | aktif | 26 | SAMA |
| 2872 | update_print_no_hardcopy | `*/2 3-23,0-1 * * *` | `*/2 3-23,0-1 * * *` | aktif | 25 | SAMA |
| 2879 | update_status_print_billing | `1 5 * * *` | `1 5 * * *` | komentar | 32 | SAMA |

### altorina

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 3286 | billing_file | `*/10 * * * *` | `*/10 * * * *` | komentar | 488 | SAMA |
| 3284 | clear_inactive_temp_issued | `2 5 * * *` | `2 5 * * *` | aktif | 486 | SAMA |
| 3285 | clear_shared_folder_opsigo | `3 5 * * *` | `3 5 * * *` | aktif | 487 | SAMA |
| 3281 | e_invoice | `*/5 22-23,0-3 * * *` | `*/5 22-23,0-3 * * *` | aktif | 483 | SAMA |
| 3277 | kill_process_timeout | `*/1 * * * *` | `*/1 * * * *` | komentar | 477 | SAMA |
| 3280 | post_invoice_to_opsigo | `*/5 * * * *` | `*/5 * * * *` | komentar | 482 | SAMA |
| 3289 | recurring | `0 3 * * *` | `0 3 * * *` | komentar | 491 | SAMA |
| 3275 | repost | `*/5 * * * *` | `*/5 * * * *` | aktif | 475 | SAMA |
| 3279 | repost_bca_api | `10 5 * * *` | `10 5 * * *` | komentar | 480 | SAMA |
| 3278 | request_bca_api | `0 5 * * *` | `0 5 * * *` | komentar | 479 | SAMA |
| 3276 | update_balance_trx | `*/7 * * * *` | `*/7 * * * *` | aktif | 476 | SAMA |
| 3283 | update_expired_user | `1 5 * * *` | `1 5 * * *` | aktif | 485 | SAMA |
| 3282 | update_print_no_hardcopy | `*/2 * * * *` | `*/2 * * * *` | aktif | 484 | SAMA |
| 3288 | update_status_print_billing | `0 0 * * *` | `0 0 * * *` | komentar | 490 | SAMA |

### aneka

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 3162 | auto_mail_sp | `0 21 * * *` | `0 21 * * *` | aktif | 348 | SAMA |
| 3166 | billing_file | `*/10 * * * *` | `*/10 * * * *` | komentar | 352 | SAMA |
| 3164 | clear_inactive_temp_issued | `1 5 * * *` | `1 5 * * *` | aktif | 350 | SAMA |
| 3165 | clear_shared_folder_opsigo | `2 5 * * *` | `2 5 * * *` | aktif | 351 | SAMA |
| 3163 | e_invoice | `*/5 22-23,0-3 * * *` | `*/5 22-23,0-3 * * *` | aktif | 349 | SAMA |
| 3158 | kill_process_timeout | `*/1 6-18 * * *` | `*/1 6-18 * * *` | komentar | 343 | SAMA |
| 3159 | post_invoice_to_opsigo | `*/5 3-23,0-1 * * *` | `*/5 3-23,0-1 * * *` | aktif | 344 | SAMA |
| 3169 | recurring | `0 3 * * *` | `0 3 * * *` | komentar | 355 | SAMA |
| 3156 | repost | `*/2 * * * *` | `*/2 3-23,0-1 * * *` | aktif | 340 | UPDATE |
| 3157 | update_balance_trx | `*/7 3-23,0-1 * * *` | `*/7 3-23,0-1 * * *` | aktif | 342 | SAMA |
| 3161 | update_expired_user | `0 21 * * *` | `0 21 * * *` | aktif | 347 | SAMA |
| 3160 | update_print_no_hardcopy | `*/2 6-18 * * *` | `*/2 6-18 * * *` | aktif | 346 | SAMA |
| 3168 | update_status_print_billing | `0 0 * * *` | `0 0 * * *` | komentar | 354 | SAMA |

### anta

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 3022 | auto_billing | `*/10 1,4-5 * * *` | `*/10 1,4-5 * * *` | aktif | 207 | SAMA |
| 3015 | auto_mail_credit_limit | `30 9 * * *` | `30 9 * * *` | aktif | 200 | SAMA |
| 3011 | auto_mail_sp | `0 21 * * *` | `0 21 * * *` | aktif | 194 | SAMA |
| 3018 | billing_file | `*/10 * * * *` | `*/10 * * * *` | komentar | 203 | SAMA |
| 3016 | clear_inactive_temp_issued | `10 5 * * *` | `10 5 * * *` | aktif | 201 | SAMA |
| 3017 | clear_shared_folder_opsigo | `20 5 * * *` | `20 5 * * *` | aktif | 202 | SAMA |
| 3010 | e_invoice | `*/5 21-23,0-1 * * *` | `*/5 21-23,0-1 * * *` | aktif | 193 | SAMA |
| 3007 | kill_process_timeout | `*/1 6-18 * * *` | `*/1 6-18 * * *` | komentar | 189 | SAMA |
| 3012 | post_invoice_to_opsigo | `*/5 19-23,0-1 * * *` | `*/5 19-23,0-1 * * *` | aktif | 196 | SAMA |
| 3021 | recurring | `0 3 * * *` | `0 3 * * *` | komentar | 206 | SAMA |
| 3005 | repost | `*/5 3-23,0-1 * * *` | `*/5 3-23,0-1 * * *` | aktif | 187 | SAMA |
| 3009 | repost_bca_api | `10 5 * * *` | `10 5 * * *` | aktif | 192 | SAMA |
| 3023 | repost_error | `*/10 22 * * *` | `*/10 22 * * *` | aktif | 208 | SAMA |
| 3008 | request_bca_api | `0 5 * * *` | `0 5 * * *` | aktif | 191 | SAMA |
| 3006 | update_balance_trx | `*/7 3-23,0-1 * * *` | `*/7 3-23,0-1 * * *` | aktif | 188 | SAMA |
| 3014 | update_expired_user | `0 21 * * *` | `0 21 * * *` | aktif | 199 | SAMA |
| 3013 | update_print_no_hardcopy | `*/5 6-20 * * *` | `*/5 6-20 * * *` | aktif | 198 | SAMA |
| 3020 | update_status_print_billing | `0 0 * * *` | `0 0 * * *` | komentar | 205 | SAMA |

### bravo

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 3274 | auto_billing | `*/10 1,4-5 * * *` | `*/10 1,4-5 * * *` | aktif | 472 | SAMA |
| 3270 | billing_file | `*/10 * * * *` | `*/10 * * * *` | komentar | 468 | SAMA |
| 3268 | clear_inactive_temp_issued | `2 5 * * *` | `2 5 * * *` | aktif | 466 | SAMA |
| 3269 | clear_shared_folder_opsigo | `3 5 * * *` | `3 5 * * *` | aktif | 467 | SAMA |
| 3265 | e_invoice | `*/5 22-23,0-3 * * *` | `*/5 22-23,0-3 * * *` | aktif | 463 | SAMA |
| 3261 | kill_process_timeout | `*/1 * * * *` | `*/1 * * * *` | komentar | 457 | SAMA |
| 3264 | post_invoice_to_opsigo | `*/5 * * * *` | `*/5 * * * *` | komentar | 462 | SAMA |
| 3273 | recurring | `0 3 * * *` | `0 3 * * *` | komentar | 471 | SAMA |
| 3259 | repost | `*/5 * * * *` | `*/5 * * * *` | aktif | 455 | SAMA |
| 3263 | repost_bca_api | `10 5 * * *` | `10 5 * * *` | komentar | 460 | SAMA |
| 3262 | request_bca_api | `0 5 * * *` | `0 5 * * *` | komentar | 459 | SAMA |
| 3260 | update_balance_trx | `*/7 * * * *` | `*/7 * * * *` | aktif | 456 | SAMA |
| 3267 | update_expired_user | `1 5 * * *` | `1 5 * * *` | aktif | 465 | SAMA |
| 3266 | update_print_no_hardcopy | `*/2 * * * *` | `*/2 * * * *` | aktif | 464 | SAMA |
| 3272 | update_status_print_billing | `0 0 * * *` | `0 0 * * *` | komentar | 470 | SAMA |

### dev5

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 2969 | repost | `*/6 * * * *` | `*/6 * * * *` | komentar | 137 | SAMA |
| 2970 | update_balance_trx | `*/7 * * * *` | `*/7 * * * *` | komentar | 138 | SAMA |

### excape

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 3347 | auto_summary | `*/5 * * * *` | `*/5 * * * *` | komentar | 530 | UPDATE |
| 3348 | billing_file | `*/5 * * * *` | `*/10 * * * *` | komentar | 526 | UPDATE |
| 3349 | clear_inactive_temp_issued | `*/5 * * * *` | `2 5 * * *` | aktif | 524 | UPDATE |
| 3350 | clear_shared_folder_opsigo | `*/5 * * * *` | `3 5 * * *` | aktif | 525 | UPDATE |
| 3351 | e_invoice | `*/5 * * * *` | `*/5 22-23,0-3 * * *` | aktif | 521 | UPDATE |
| 3353 | kill_process_timeout | `*/5 * * * *` | `*/1 * * * *` | komentar | 516 | UPDATE |
| 3354 | post_invoice_to_opsigo | `*/5 * * * *` | `*/5 * * * *` | komentar | 520 | UPDATE |
| 3355 | recurring | `*/5 * * * *` | `0 3 * * *` | komentar | 529 | UPDATE |
| 3356 | repost | `*/5 * * * *` | `*/5 * * * *` | aktif | 514 | UPDATE |
| 3357 | repost_bca_api | `*/5 * * * *` | `10 5 * * *` | komentar | 518 | UPDATE |
| 3359 | request_bca_api | `*/5 * * * *` | `0 5 * * *` | komentar | 517 | UPDATE |
| 3360 | update_balance_trx | `*/5 * * * *` | `*/7 * * * *` | aktif | 515 | UPDATE |
| 3361 | update_expired_user | `*/5 * * * *` | `1 5 * * *` | aktif | 523 | UPDATE |
| 3362 | update_print_no_hardcopy | `*/5 * * * *` | `*/2 * * * *` | aktif | 522 | UPDATE |

### gardi

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 3049 | auto_mail_sp | `0 21 * * *` | `0 21 * * *` | aktif | 244 | SAMA |
| 3053 | billing_file | `*/10 * * * *` | `*/10 * * * *` | komentar | 248 | SAMA |
| 3051 | clear_inactive_temp_issued | `2 5 * * *` | `2 5 * * *` | aktif | 246 | SAMA |
| 3052 | clear_shared_folder_opsigo | `3 5 * * *` | `3 5 * * *` | aktif | 247 | SAMA |
| 3050 | e_invoice | `*/5 22-23,0-3 * * *` | `*/5 22-23,0-3 * * *` | aktif | 245 | SAMA |
| 3045 | kill_process_timeout | `*/1 * * * *` | `*/1 * * * *` | komentar | 239 | SAMA |
| 3046 | post_invoice_to_opsigo | `*/5 * * * *` | `*/5 * * * *` | aktif | 240 | SAMA |
| 3056 | recurring | `0 3 * * *` | `0 1 * * *` | aktif | 249 | UPDATE |
| 3043 | repost | `*/2 * * * *` | `*/2 * * * *` | aktif | 237 | SAMA |
| 3044 | update_balance_trx | `*/7 * * * *` | `*/7 * * * *` | aktif | 238 | SAMA |
| 3048 | update_expired_user | `1 5 * * *` | `1 5 * * *` | aktif | 243 | SAMA |
| 3047 | update_print_no_hardcopy | `*/2 * * * *` | `*/2 * * * *` | aktif | 242 | SAMA |
| 3055 | update_status_print_billing | `0 0 * * *` | `0 0 * * *` | komentar | 250 | SAMA |

### globalwisata

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 3118 | auto_billing | `*/10 1,4-5 * * *` | `*/10 1,4-5 * * *` | aktif | 306 | SAMA |
| 3110 | auto_mail_sp | `0 21 * * *` | `0 21 * * *` | aktif | 298 | SAMA |
| 3114 | billing_file | `*/10 * * * *` | `*/10 * * * *` | komentar | 302 | SAMA |
| 3112 | clear_inactive_temp_issued | `1 5 * * *` | `1 5 * * *` | aktif | 300 | SAMA |
| 3113 | clear_shared_folder_opsigo | `2 5 * * *` | `2 5 * * *` | aktif | 301 | SAMA |
| 3111 | e_invoice | `*/5 22-23,0-3 * * *` | `*/5 22-23,0-3 * * *` | aktif | 299 | SAMA |
| 3106 | kill_process_timeout | `*/1 6-18 * * *` | `*/1 6-18 * * *` | komentar | 293 | SAMA |
| 3107 | post_invoice_to_opsigo | `*/5 3-23,0-1 * * *` | `*/5 3-23,0-1 * * *` | aktif | 294 | SAMA |
| 3117 | recurring | `0 3 * * *` | `0 3 * * *` | komentar | 305 | SAMA |
| 3103 | repost | `*/2 3-23,0-1 * * *` | `*/2 3-23,0-1 * * *` | aktif | 290 | SAMA |
| 3105 | update_balance_trx | `*/7 3-23,0-1 * * *` | `*/7 3-23,0-1 * * *` | aktif | 292 | SAMA |
| 3109 | update_expired_user | `0 21 * * *` | `0 21 * * *` | aktif | 297 | SAMA |
| 3108 | update_print_no_hardcopy | `*/2 6-18 * * *` | `*/2 6-18 * * *` | aktif | 296 | SAMA |
| 3116 | update_status_print_billing | `0 0 * * *` | `0 0 * * *` | komentar | 304 | SAMA |

### gn

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 3453 | auto_summary | `*/10 1,4-5 * * *` | `*/5 * * * *` | komentar | 19 | UPDATE |
| 2865 | billing_file | `*/10 * * * *` | `*/10 * * * *` | komentar | 15 | SAMA |
| 2863 | clear_inactive_temp_issued | `2 5 * * *` | `2 5 * * *` | aktif | 13 | SAMA |
| 2864 | clear_shared_folder_opsigo | `3 5 * * *` | `3 5 * * *` | aktif | 14 | SAMA |
| 2860 | e_invoice | `*/5 12-13,18-19,0-3 * * *` | `*/5 12-13,18-19,0-3 * * *` | aktif | 10 | SAMA |
| 2856 | kill_process_timeout | `*/1 * * * *` | `*/1 * * * *` | komentar | 4 | SAMA |
| 2859 | post_invoice_to_opsigo | `*/5 * * * *` | `*/5 * * * *` | aktif | 9 | SAMA |
| 2866 | recurring | `0 3 * * *` | `0 3 * * *` | aktif | 18 | UPDATE |
| 2854 | repost | `*/6 * * * *` | `*/6 * * * *` | aktif | 2 | SAMA |
| 2858 | repost_bca_api | `10 5 * * *` | `10 5 * * *` | aktif | 7 | SAMA |
| 2857 | request_bca_api | `0 5 * * *` | `0 5 * * *` | aktif | 6 | SAMA |
| 2855 | update_balance_trx | `*/7 * * * *` | `*/7 * * * *` | aktif | 3 | SAMA |
| 2862 | update_expired_user | `1 5 * * *` | `1 5 * * *` | aktif | 12 | SAMA |
| 2861 | update_print_no_hardcopy | `*/2 * * * *` | `*/2 * * * *` | aktif | 11 | SAMA |
| 2867 | update_status_print_billing | `0 0 * * *` | `0 0 * * *` | komentar | 17 | SAMA |

### gns

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 3459 | auto_summary | `*/5 * * * *` | `*/5 * * * *` | komentar | 433 | UPDATE |
| 3226 | billing_file | `*/10 * * * *` | `*/10 * * * *` | komentar | 429 | SAMA |
| 3224 | clear_inactive_temp_issued | `2 5 * * *` | `2 5 * * *` | aktif | 427 | SAMA |
| 3225 | clear_shared_folder_opsigo | `3 5 * * *` | `3 5 * * *` | aktif | 428 | SAMA |
| 3221 | e_invoice | `*/5 12-13,18-19,0-3 * * *` | `*/5 12-13,18-19,0-3 * * *` | aktif | 424 | SAMA |
| 3217 | kill_process_timeout | `*/1 * * * *` | `*/1 * * * *` | komentar | 418 | SAMA |
| 3220 | post_invoice_to_opsigo | `*/5 * * * *` | `*/5 * * * *` | aktif | 423 | SAMA |
| 3229 | recurring | `0 3 * * *` | `0 3 * * *` | aktif | 432 | SAMA |
| 3215 | repost | `*/6 * * * *` | `*/6 * * * *` | aktif | 416 | SAMA |
| 3219 | repost_bca_api | `10 5 * * *` | `10 5 * * *` | aktif | 421 | SAMA |
| 3218 | request_bca_api | `0 5 * * *` | `0 5 * * *` | aktif | 420 | SAMA |
| 3216 | update_balance_trx | `*/7 * * * *` | `*/7 * * * *` | aktif | 417 | SAMA |
| 3223 | update_expired_user | `1 5 * * *` | `1 5 * * *` | aktif | 426 | SAMA |
| 3222 | update_print_no_hardcopy | `*/2 * * * *` | `*/2 * * * *` | aktif | 425 | SAMA |
| 3228 | update_status_print_billing | `0 0 * * *` | `0 0 * * *` | komentar | 431 | SAMA |

### hits_travel

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 3326 | auto_mail_sp | `*/5 * * * *` | `0 21 * * *` | komentar | 335 | UPDATE |
| 3329 | clear_inactive_temp_issued | `*/5 * * * *` | `0 0 * * *` | komentar | 337 | UPDATE |
| 3331 | e_invoice | `*/5 * * * *` | `*/5 22-23,0-3 * * *` | komentar | 336 | UPDATE |
| 3333 | kill_process_timeout | `*/5 * * * *` | `*/1 6-18 * * *` | komentar | 330 | UPDATE |
| 3334 | post_invoice_to_opsigo | `*/5 * * * *` | `*/5 3-23,0-1 * * *` | komentar | 331 | UPDATE |
| 3336 | repost | `*/5 * * * *` | `*/2 * * * *` | komentar | 328 | UPDATE |
| 3340 | update_balance_trx | `*/5 * * * *` | `*/7 3-23,0-1 * * *` | komentar | 329 | UPDATE |
| 3341 | update_expired_user | `*/5 * * * *` | `0 21 * * *` | komentar | 334 | UPDATE |
| 3342 | update_print_no_hardcopy | `*/5 * * * *` | `*/2 6-18 * * *` | komentar | 333 | UPDATE |

### jontru

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 3206 | auto_mail_sp | `0 21 * * *` | `0 21 * * *` | aktif | 402 | SAMA |
| 3475 | auto_summary | `*/5 * * * *` | `*/5 * * * *` | komentar | 413 | UPDATE |
| 3210 | billing_file | `*/10 * * * *` | `*/10 * * * *` | komentar | 408 | SAMA |
| 3208 | clear_inactive_temp_issued | `1 5 * * *` | `1 5 * * *` | aktif | 404 | SAMA |
| 3209 | clear_shared_folder_opsigo | `2 5 * * *` | `2 5 * * *` | aktif | 405 | SAMA |
| 3207 | e_invoice | `*/5 22-23,0-3 * * *` | `*/5 22-23,0-3 * * *` | aktif | 403 | SAMA |
| 3213 | kcic_email | `0 3 * * *` | `0 3 * * *` | aktif | 411 | SAMA |
| 3202 | kill_process_timeout | `*/1 6-18 * * *` | `*/1 6-18 * * *` | aktif | 397 | SAMA |
| 3203 | post_invoice_to_opsigo | `*/5 3-23,0-1 * * *` | `*/5 3-23,0-1 * * *` | aktif | 398 | SAMA |
| 3211 | recurring | `0 1 * * *` | `0 1 * * *` | komentar | 409 | SAMA |
| 3199 | repost | `*/2 3-23,0-1 * * *` | `*/2 3-23,0-1 * * *` | aktif | 394 | SAMA |
| 3214 | repost_error | `1-59/5 * * * *` | `1-59/5 * * * *` | aktif | 412 | SAMA |
| 3201 | update_balance_trx | `*/7 3-23,0-1 * * *` | `*/7 3-23,0-1 * * *` | aktif | 396 | SAMA |
| 3205 | update_expired_user | `0 21 * * *` | `0 21 * * *` | aktif | 401 | SAMA |
| 3204 | update_print_no_hardcopy | `*/2 6-18 * * *` | `*/2 6-18 * * *` | aktif | 400 | SAMA |
| 3212 | update_status_print_billing | `0 0 * * *` | `0 0 * * *` | komentar | 410 | SAMA |

### kia

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 2901 | billing_file | `*/10 * * * *` | `*/10 * * * *` | komentar | 61 | SAMA |
| 2899 | clear_inactive_temp_issued | `2 5 * * *` | `2 5 * * *` | aktif | 59 | SAMA |
| 2900 | clear_shared_folder_opsigo | `3 5 * * *` | `3 5 * * *` | aktif | 60 | SAMA |
| 2896 | kill_process_timeout | `*/1 * * * *` | `*/1 * * * *` | komentar | 56 | SAMA |
| 2904 | recurring | `0 3 * * *` | `0 3 * * *` | komentar | 64 | SAMA |
| 2894 | repost | `*/6 * * * *` | `*/6 * * * *` | aktif | 54 | SAMA |
| 2895 | update_balance_trx | `*/7 * * * *` | `*/7 * * * *` | aktif | 55 | SAMA |
| 2898 | update_expired_user | `1 5 * * *` | `1 5 * * *` | aktif | 58 | SAMA |
| 2897 | update_print_no_hardcopy | `*/2 * * * *` | `*/2 * * * *` | aktif | 57 | SAMA |
| 2903 | update_status_print_billing | `0 0 * * *` | `0 0 * * *` | komentar | 63 | SAMA |

### kiable

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 3255 | billing_file | `*/10 * * * *` | `*/10 * * * *` | komentar | 449 | SAMA |
| 3253 | clear_inactive_temp_issued | `0 0 * * *` | `0 0 * * *` | komentar | 447 | SAMA |
| 3254 | clear_shared_folder_opsigo | `0 0 * * *` | `0 0 * * *` | komentar | 448 | SAMA |
| 3250 | e_invoice | `*/5 22-23,0-3 * * *` | `*/5 22-23,0-3 * * *` | aktif | 444 | SAMA |
| 3246 | kill_process_timeout | `*/1 * * * *` | `*/1 * * * *` | komentar | 438 | SAMA |
| 3249 | post_invoice_to_opsigo | `*/5 * * * *` | `*/5 * * * *` | komentar | 443 | SAMA |
| 3258 | recurring | `0 3 * * *` | `0 3 * * *` | komentar | 452 | SAMA |
| 3244 | repost | `*/5 * * * *` | `*/5 * * * *` | aktif | 436 | SAMA |
| 3248 | repost_bca_api | `10 5 * * *` | `10 5 * * *` | komentar | 441 | SAMA |
| 3247 | request_bca_api | `0 5 * * *` | `0 5 * * *` | komentar | 440 | SAMA |
| 3245 | update_balance_trx | `*/7 * * * *` | `*/7 * * * *` | aktif | 437 | SAMA |
| 3252 | update_expired_user | `0 0 * * *` | `0 0 * * *` | komentar | 446 | SAMA |
| 3251 | update_print_no_hardcopy | `*/2 * * * *` | `*/2 * * * *` | aktif | 445 | SAMA |
| 3257 | update_status_print_billing | `0 0 * * *` | `0 0 * * *` | komentar | 451 | SAMA |

### kiaxxxharmoni

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 3138 | auto_mail_sp | `0 21 * * *` | `0 21 * * *` | aktif | 317 | SAMA |
| 3142 | billing_file | `*/10 * * * *` | `*/10 * * * *` | komentar | 321 | SAMA |
| 3140 | clear_inactive_temp_issued | `1 5 * * *` | `1 5 * * *` | aktif | 319 | SAMA |
| 3141 | clear_shared_folder_opsigo | `2 5 * * *` | `2 5 * * *` | aktif | 320 | SAMA |
| 3139 | e_invoice | `*/5 22-23,0-3 * * *` | `*/5 22-23,0-3 * * *` | aktif | 318 | SAMA |
| 3134 | kill_process_timeout | `*/1 6-18 * * *` | `*/1 6-18 * * *` | komentar | 312 | SAMA |
| 3135 | post_invoice_to_opsigo | `*/5 3-23,0-1 * * *` | `*/5 3-23,0-1 * * *` | aktif | 313 | SAMA |
| 3143 | recurring | `0 1 * * *` | `0 1 * * *` | komentar | 322 | SAMA |
| 3131 | repost | `*/2 3-23,0-1 * * *` | `*/2 3-23,0-1 * * *` | aktif | 309 | SAMA |
| 3133 | update_balance_trx | `*/7 3-23,0-1 * * *` | `*/7 3-23,0-1 * * *` | aktif | 311 | SAMA |
| 3137 | update_expired_user | `0 21 * * *` | `0 21 * * *` | aktif | 316 | SAMA |
| 3136 | update_print_no_hardcopy | `*/2 6-18 * * *` | `*/2 6-18 * * *` | aktif | 315 | SAMA |
| 3144 | update_status_print_billing | `0 0 * * *` | `0 0 * * *` | komentar | 323 | SAMA |

### konverm

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 3501 | auto_billing | `*/5 * * * *` | `*/10 1,4-5 * * *` | komentar | 373 | UPDATE |
| 3177 | auto_mail_sp | `0 21 * * *` | `0 21 * * *` | komentar | 366 | SAMA |
| 3181 | billing_file | `*/10 * * * *` | `*/10 * * * *` | komentar | 370 | SAMA |
| 3179 | clear_inactive_temp_issued | `1 5 * * *` | `1 5 * * *` | aktif | 368 | SAMA |
| 3180 | clear_shared_folder_opsigo | `2 5 * * *` | `2 5 * * *` | aktif | 369 | SAMA |
| 3178 | e_invoice | `*/5 22-23,0-3 * * *` | `*/5 22-23,0-3 * * *` | komentar | 367 | SAMA |
| 3173 | kill_process_timeout | `*/1 6-18 * * *` | `*/1 6-18 * * *` | aktif | 361 | SAMA |
| 3174 | post_invoice_to_opsigo | `*/5 3-23,0-1 * * *` | `*/5 3-23,0-1 * * *` | komentar | 362 | SAMA |
| 3182 | recurring | `0 1 * * *` | `0 1 * * *` | komentar | 371 | SAMA |
| 3170 | repost | `*/2 3-23,0-1 * * *` | `*/2 3-23,0-1 * * *` | aktif | 358 | SAMA |
| 3172 | update_balance_trx | `*/7 3-23,0-1 * * *` | `*/7 3-23,0-1 * * *` | aktif | 360 | SAMA |
| 3176 | update_expired_user | `0 21 * * *` | `0 21 * * *` | aktif | 365 | SAMA |
| 3175 | update_print_no_hardcopy | `*/2 6-18 * * *` | `*/2 6-18 * * *` | aktif | 364 | SAMA |
| 3183 | update_status_print_billing | `0 0 * * *` | `0 0 * * *` | komentar | 372 | SAMA |

### mitra

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 2928 | auto_billing | `*/10 1,4-5 * * *` | `*/10 1,4-5 * * *` | aktif | 93 | SAMA |
| 2924 | billing_file | `*/10 * * * *` | `*/10 * * * *` | komentar | 89 | SAMA |
| 2922 | clear_inactive_temp_issued | `2 5 * * *` | `2 5 * * *` | aktif | 87 | SAMA |
| 2923 | clear_shared_folder_opsigo | `3 5 * * *` | `3 5 * * *` | aktif | 88 | SAMA |
| 2918 | kill_process_timeout | `*/1 * * * *` | `*/1 * * * *` | komentar | 82 | SAMA |
| 2919 | post_invoice_to_opsigo | `*/5 * * * *` | `*/5 * * * *` | aktif | 83 | SAMA |
| 2927 | recurring | `0 3 * * *` | `0 3 * * *` | komentar | 92 | SAMA |
| 2916 | repost | `*/2 * * * *` | `*/2 * * * *` | aktif | 80 | SAMA |
| 2917 | update_balance_trx | `*/7 * * * *` | `*/7 * * * *` | aktif | 81 | SAMA |
| 2921 | update_expired_user | `1 5 * * *` | `1 5 * * *` | aktif | 86 | SAMA |
| 2920 | update_print_no_hardcopy | `*/2 * * * *` | `*/2 * * * *` | aktif | 85 | SAMA |
| 2926 | update_status_print_billing | `0 0 * * *` | `0 0 * * *` | komentar | 91 | SAMA |

### mnc_travel

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 2952 | auto_billing | `*/10 1,4-5 * * *` | `*/10 1,4-5 * * *` | aktif | 112 | SAMA |
| 2945 | auto_mail_credit_limit | `0 9 * * 3,5` | `0 9 * * 3,5` | aktif | 105 | SAMA |
| 2948 | billing_file | `*/10 * * * *` | `*/10 * * * *` | komentar | 108 | SAMA |
| 2946 | clear_inactive_temp_issued | `1 5 * * *` | `1 5 * * *` | aktif | 106 | SAMA |
| 2947 | clear_shared_folder_opsigo | `2 5 * * *` | `2 5 * * *` | aktif | 107 | SAMA |
| 2942 | e_invoice | `*/5 21-23,0-1 * * *` | `*/5 21-23,0-1 * * *` | aktif | 102 | SAMA |
| 2940 | kill_process_timeout | `*/1 6-18 * * *` | `*/1 6-18 * * *` | komentar | 99 | SAMA |
| 2941 | post_invoice_to_opsigo | `*/5 19-23,0-1 * * *` | `*/5 19-23,0-1 * * *` | aktif | 101 | SAMA |
| 2951 | recurring | `0 3 * * *` | `0 3 * * *` | komentar | 111 | SAMA |
| 2938 | repost | `*/6 3-23,0-1 * * *` | `*/6 3-23,0-1 * * *` | aktif | 97 | SAMA |
| 2939 | update_balance_trx | `*/7 3-23,0-1 * * *` | `*/7 3-23,0-1 * * *` | aktif | 98 | SAMA |
| 2944 | update_expired_user | `0 21 * * *` | `0 21 * * *` | aktif | 104 | SAMA |
| 2943 | update_print_no_hardcopy | `*/2 6-18 * * *` | `*/2 6-18 * * *` | aktif | 103 | SAMA |
| 2950 | update_status_print_billing | `0 0 * * *` | `0 0 * * *` | komentar | 110 | SAMA |

### mytour

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 3004 | auto_billing | `*/10 1,4-5 * * *` | `*/10 1,4-5 * * *` | aktif | 184 | SAMA |
| 2994 | auto_mail_sp | `0 21 * * *` | `0 21 * * *` | aktif | 172 | SAMA |
| 3000 | billing_file | `*/10 * * * *` | `*/10 * * * *` | komentar | 180 | SAMA |
| 2998 | clear_inactive_temp_issued | `2 5 * * *` | `2 5 * * *` | aktif | 178 | SAMA |
| 2999 | clear_shared_folder_opsigo | `3 5 * * *` | `3 5 * * *` | aktif | 179 | SAMA |
| 2993 | e_invoice | `*/5 22-23,0-3 * * *` | `*/5 22-23,0-3 * * *` | aktif | 171 | SAMA |
| 2990 | kill_process_timeout | `*/1 * * * *` | `*/1 * * * *` | komentar | 167 | SAMA |
| 2995 | post_invoice_to_opsigo | `*/5 * * * *` | `*/5 * * * *` | aktif | 174 | SAMA |
| 3003 | recurring | `0 3 * * *` | `0 3 * * *` | komentar | 183 | SAMA |
| 2988 | repost | `*/2 * * * *` | `*/2 * * * *` | aktif | 165 | SAMA |
| 2992 | repost_bca_api | `05 5 * * *` | `05 5 * * *` | komentar | 170 | SAMA |
| 2991 | request_bca_api | `0 5 * * *` | `0 5 * * *` | komentar | 169 | SAMA |
| 2989 | update_balance_trx | `*/7 * * * *` | `*/7 * * * *` | aktif | 166 | SAMA |
| 2997 | update_expired_user | `1 5 * * *` | `1 5 * * *` | aktif | 177 | SAMA |
| 2996 | update_print_no_hardcopy | `*/2 * * * *` | `*/2 * * * *` | aktif | 176 | SAMA |
| 3002 | update_status_print_billing | `0 0 * * *` | `0 0 * * *` | komentar | 182 | SAMA |

### mytrip

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 3191 | auto_mail_sp | `0 21 * * *` | `0 21 * * *` | komentar | 384 | SAMA |
| 3195 | billing_file | `*/10 * * * *` | `*/10 * * * *` | komentar | 388 | SAMA |
| 3193 | clear_inactive_temp_issued | `1 5 * * *` | `1 5 * * *` | aktif | 386 | SAMA |
| 3194 | clear_shared_folder_opsigo | `2 5 * * *` | `2 5 * * *` | aktif | 387 | SAMA |
| 3192 | e_invoice | `*/5 22-23,0-3 * * *` | `*/5 22-23,0-3 * * *` | komentar | 385 | SAMA |
| 3187 | kill_process_timeout | `*/1 6-18 * * *` | `*/1 6-18 * * *` | aktif | 379 | SAMA |
| 3188 | post_invoice_to_opsigo | `*/5 3-23,0-1 * * *` | `*/5 3-23,0-1 * * *` | komentar | 380 | SAMA |
| 3198 | recurring | `0 3 * * *` | `0 3 * * *` | komentar | 391 | SAMA |
| 3184 | repost | `*/2 3-23,0-1 * * *` | `*/2 3-23,0-1 * * *` | aktif | 376 | SAMA |
| 3186 | update_balance_trx | `*/7 3-23,0-1 * * *` | `*/7 3-23,0-1 * * *` | aktif | 378 | SAMA |
| 3190 | update_expired_user | `0 21 * * *` | `0 21 * * *` | aktif | 383 | SAMA |
| 3189 | update_print_no_hardcopy | `*/2 6-18 * * *` | `*/2 6-18 * * *` | aktif | 382 | SAMA |
| 3197 | update_status_print_billing | `0 0 * * *` | `0 0 * * *` | komentar | 390 | SAMA |

### pij

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 3063 | auto_mail_sp | `0 21 * * *` | `0 21 * * *` | aktif | 262 | SAMA |
| 3067 | billing_file | `*/10 * * * *` | `*/10 * * * *` | komentar | 266 | SAMA |
| 3065 | clear_inactive_temp_issued | `1 5 * * *` | `1 5 * * *` | aktif | 264 | SAMA |
| 3066 | clear_shared_folder_opsigo | `2 5 * * *` | `2 5 * * *` | aktif | 265 | SAMA |
| 3064 | e_invoice | `*/5 22-23,0-3 * * *` | `*/5 22-23,0-3 * * *` | aktif | 263 | SAMA |
| 3059 | kill_process_timeout | `*/1 6-18 * * *` | `*/1 6-18 * * *` | komentar | 257 | SAMA |
| 3060 | post_invoice_to_opsigo | `*/5 3-23,0-1 * * *` | `*/5 3-23,0-1 * * *` | aktif | 258 | SAMA |
| 3070 | recurring | `0 3 * * *` | `0 3 * * *` | komentar | 269 | SAMA |
| 3057 | repost | `*/2 3-23,0-1 * * *` | `*/2 3-23,0-1 * * *` | aktif | 255 | SAMA |
| 3058 | update_balance_trx | `*/7 3-23,0-1 * * *` | `*/7 3-23,0-1 * * *` | aktif | 256 | SAMA |
| 3062 | update_expired_user | `1 5 * * *` | `1 5 * * *` | aktif | 261 | SAMA |
| 3061 | update_print_no_hardcopy | `*/2 6-18 * * *` | `*/2 6-18 * * *` | aktif | 260 | SAMA |
| 3069 | update_status_print_billing | `0 0 * * *` | `0 0 * * *` | komentar | 268 | SAMA |

### privela

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 3549 | auto_summary | `*/5 * * * *` | `*/5 * * * *` | komentar | 511 | UPDATE |
| 3301 | billing_file | `*/10 * * * *` | `*/10 * * * *` | komentar | 507 | SAMA |
| 3299 | clear_inactive_temp_issued | `2 5 * * *` | `2 5 * * *` | aktif | 505 | SAMA |
| 3300 | clear_shared_folder_opsigo | `3 5 * * *` | `3 5 * * *` | aktif | 506 | SAMA |
| 3296 | e_invoice | `*/5 22-23,0-3 * * *` | `*/5 22-23,0-3 * * *` | aktif | 502 | SAMA |
| 3292 | kill_process_timeout | `*/1 * * * *` | `*/1 * * * *` | komentar | 496 | SAMA |
| 3295 | post_invoice_to_opsigo | `*/5 * * * *` | `*/5 * * * *` | komentar | 501 | SAMA |
| 3303 | recurring | `0 3 * * *` | `0 3 * * *` | komentar | 510 | SAMA |
| 3290 | repost | `*/5 * * * *` | `*/5 * * * *` | aktif | 494 | SAMA |
| 3294 | repost_bca_api | `10 5 * * *` | `10 5 * * *` | komentar | 499 | SAMA |
| 3293 | request_bca_api | `0 5 * * *` | `0 5 * * *` | komentar | 498 | SAMA |
| 3291 | update_balance_trx | `*/7 * * * *` | `*/7 * * * *` | aktif | 495 | SAMA |
| 3298 | update_expired_user | `1 5 * * *` | `1 5 * * *` | aktif | 504 | SAMA |
| 3297 | update_print_no_hardcopy | `*/2 * * * *` | `*/2 * * * *` | aktif | 503 | SAMA |

### psa

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 3030 | auto_mail_sp | `0 21 * * *` | `0 21 * * *` | aktif | 218 | SAMA |
| 3036 | billing_file | `*/10 * * * *` | `*/10 * * * *` | komentar | 226 | SAMA |
| 3034 | clear_inactive_temp_issued | `2 5 * * *` | `2 5 * * *` | aktif | 224 | SAMA |
| 3035 | clear_shared_folder_opsigo | `3 5 * * *` | `3 5 * * *` | aktif | 225 | SAMA |
| 3029 | e_invoice | `*/5 22-23,0-3 * * *` | `*/5 22-23,0-3 * * *` | aktif | 217 | SAMA |
| 3026 | kill_process_timeout | `*/1 * * * *` | `*/1 * * * *` | komentar | 213 | SAMA |
| 3031 | post_invoice_to_opsigo | `*/5 * * * *` | `*/5 * * * *` | aktif | 220 | SAMA |
| 3037 | recurring | `0 1 * * *` | `0 1 * * *` | komentar | 227 | SAMA |
| 3024 | repost | `*/2 * * * *` | `*/2 * * * *` | aktif | 211 | SAMA |
| 3028 | repost_bca_api | `05 5 * * *` | `05 5 * * *` | komentar | 216 | SAMA |
| 3027 | request_bca_api | `0 5 * * *` | `0 5 * * *` | komentar | 215 | SAMA |
| 3025 | update_balance_trx | `*/7 * * * *` | `*/7 * * * *` | aktif | 212 | SAMA |
| 3033 | update_expired_user | `1 5 * * *` | `1 5 * * *` | aktif | 223 | SAMA |
| 3032 | update_print_no_hardcopy | `*/2 * * * *` | `*/2 * * * *` | aktif | 222 | SAMA |
| 3038 | update_status_print_billing | `0 0 * * *` | `0 0 * * *` | komentar | 228 | SAMA |

### qa1

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 2967 | auto_summary | `*/5 * * * *` | `*/5 * * * *` | komentar | 130 | SAMA |
| 2968 | update_balance_trx | `*/7 * * * *` | `*/7 * * * *` | komentar | 134 | SAMA |

### qa2

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 2958 | auto_mail_sp | `*/10 * * * *` | `*/10 * * * *` | komentar | 118 | SAMA |
| 2965 | billing_file | `*/5 * * * *` | `*/5 * * * *` | komentar | 128 | SAMA |
| 2963 | clear_inactive_temp_issued | `0 0 * * *` | `0 0 * * *` | komentar | 126 | SAMA |
| 2964 | clear_shared_folder_opsigo | `0 0 * * *` | `0 0 * * *` | komentar | 127 | SAMA |
| 2957 | e_invoice | `*/5 21-23,0-1 * * *` | `*/5 21-23,0-1 * * *` | komentar | 117 | SAMA |
| 2960 | post_invoice_to_opsigo | `*/5 * * * *` | `*/5 * * * *` | komentar | 121 | SAMA |
| 2959 | repost | `1-59/10 * * * *` | `1-59/5 * * * *` | aktif | 119 | UPDATE |
| 2962 | repost_bca_api | `*/5 * * * *` | `*/5 * * * *` | komentar | 125 | SAMA |
| 2961 | request_bca_api | `*/5 * * * *` | `*/5 * * * *` | komentar | 124 | SAMA |
| 2956 | update_balance_trx | `*/7 * * * *` | `*/7 * * * *` | aktif | 116 | SAMA |
| 2966 | update_status_print_billing | `0 0 * * *` | `0 0 * * *` | komentar | 129 | SAMA |

### rsp

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 3042 | recurring | `0 3 * * *` | `0 3 * * *` | aktif | 234 | SAMA |
| 3040 | update_balance_trx | `*/7 * * * *` | `*/7 * * * *` | aktif | 232 | SAMA |
| 3041 | update_expired_user | `1 5 * * *` | `1 5 * * *` | aktif | 233 | SAMA |

### tx

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 2912 | billing_file | `*/10 * * * *` | `*/10 * * * *` | komentar | 74 | SAMA |
| 2910 | clear_inactive_temp_issued | `2 5 * * *` | `2 5 * * *` | aktif | 72 | SAMA |
| 2911 | clear_shared_folder_opsigo | `3 5 * * *` | `3 5 * * *` | aktif | 73 | SAMA |
| 2907 | kill_process_timeout | `*/1 * * * *` | `*/1 * * * *` | komentar | 69 | SAMA |
| 2915 | recurring | `0 3 * * *` | `0 3 * * *` | komentar | 77 | SAMA |
| 2905 | repost | `*/2 * * * *` | `*/2 * * * *` | aktif | 67 | SAMA |
| 2906 | update_balance_trx | `*/7 * * * *` | `*/7 * * * *` | aktif | 68 | SAMA |
| 2909 | update_expired_user | `1 5 * * *` | `1 5 * * *` | aktif | 71 | SAMA |
| 2908 | update_print_no_hardcopy | `*/2 * * * *` | `*/2 * * * *` | aktif | 70 | SAMA |
| 2914 | update_status_print_billing | `0 0 * * *` | `0 0 * * *` | komentar | 76 | SAMA |

### vaya

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 2887 | auto_mail_sp | `0 21 * * *` | `0 21 * * *` | komentar | 43 | SAMA |
| 2891 | clear_inactive_temp_issued | `0 0 * * *` | `0 0 * * *` | komentar | 49 | SAMA |
| 2892 | clear_shared_folder_opsigo | `0 0 * * *` | `0 0 * * *` | komentar | 50 | SAMA |
| 2886 | e_invoice | `*/5 21-23,0-1 * * *` | `*/5 21-23,0-1 * * *` | komentar | 42 | SAMA |
| 2883 | kill_process_timeout | `*/1 6-18 * * *` | `*/1 6-18 * * *` | komentar | 38 | SAMA |
| 2888 | post_invoice_to_opsigo | `*/5 19-23,0-1 * * *` | `*/5 19-23,0-1 * * *` | komentar | 45 | SAMA |
| 2881 | repost | `*/5 3-23,0-1 * * *` | `*/5 3-23,0-1 * * *` | aktif | 36 | SAMA |
| 2885 | repost_bca_api | `10 5 * * *` | `10 5 * * *` | aktif | 41 | SAMA |
| 2884 | request_bca_api | `0 5 * * *` | `0 5 * * *` | aktif | 40 | SAMA |
| 2882 | update_balance_trx | `*/7 3-23,0-1 * * *` | `*/7 3-23,0-1 * * *` | aktif | 37 | SAMA |
| 2890 | update_expired_user | `0 21 * * *` | `0 21 * * *` | komentar | 48 | SAMA |
| 2889 | update_print_no_hardcopy | `*/2 6-20 * * *` | `*/2 6-20 * * *` | komentar | 47 | SAMA |
| 2893 | update_status_print_billing | `0 0 * * *` | `0 0 * * *` | komentar | 51 | SAMA |

### wisman

| ID | Task | Cron DB | Cron TXT | Status TXT | Baris | Hasil |
| ---: | --- | --- | --- | --- | ---: | --- |
| 2978 | auto_mail_sp | `0 21 * * *` | `0 21 * * *` | aktif | 151 | SAMA |
| 2984 | billing_file | `*/10 * * * *` | `*/10 * * * *` | komentar | 159 | SAMA |
| 2982 | clear_inactive_temp_issued | `2 5 * * *` | `2 5 * * *` | aktif | 157 | SAMA |
| 2983 | clear_shared_folder_opsigo | `3 5 * * *` | `3 5 * * *` | aktif | 158 | SAMA |
| 2977 | e_invoice | `*/5 22-23,0-3 * * *` | `*/5 22-23,0-3 * * *` | aktif | 150 | SAMA |
| 2974 | kill_process_timeout | `*/1 * * * *` | `*/1 * * * *` | komentar | 146 | SAMA |
| 2979 | post_invoice_to_opsigo | `*/5 * * * *` | `*/5 * * * *` | aktif | 153 | SAMA |
| 2987 | recurring | `0 3 * * *` | `0 3 * * *` | komentar | 162 | SAMA |
| 2972 | repost | `*/2 * * * *` | `*/2 * * * *` | aktif | 144 | SAMA |
| 2976 | repost_bca_api | `05 5 * * *` | `05 5 * * *` | komentar | 149 | SAMA |
| 2975 | request_bca_api | `0 5 * * *` | `0 5 * * *` | komentar | 148 | SAMA |
| 2973 | update_balance_trx | `*/7 * * * *` | `*/7 * * * *` | aktif | 145 | SAMA |
| 2981 | update_expired_user | `1 5 * * *` | `1 5 * * *` | aktif | 156 | SAMA |
| 2980 | update_print_no_hardcopy | `*/2 * * * *` | `*/2 * * * *` | aktif | 155 | SAMA |
| 2986 | update_status_print_billing | `0 0 * * *` | `0 0 * * *` | komentar | 161 | SAMA |

## Baris `crontab.txt` yang tidak dipetakan

Baris berikut tidak punya Task Template atau Client di DB, jadi tidak menghasilkan
update. Bila salah satunya perlu dijalankan scheduler, Task Template (atau Client)
harus dibuat dulu lewat UI.

| Baris | Status | Client/folder | Script/task | Cron | Alasan |
| ---: | --- | --- | --- | --- | --- |
| 5 | aktif | gn | update_token_bca | `*/50 * * * *` | script/task 'update_token_bca' tidak punya Task Template |
| 8 | aktif | gn | updateTokenOpsigo | `*/59 * * * *` | script/task 'updateTokenOpsigo' tidak punya Task Template |
| 39 | komentar | vaya | update_token_bca | `*/50 3-23,0-1 * * *` | script/task 'update_token_bca' tidak punya Task Template |
| 44 | komentar | vaya | updateTokenOpsigo | `*/59 3-23,0-1 * * *` | script/task 'updateTokenOpsigo' tidak punya Task Template |
| 46 | komentar | vaya | draftBilling | `0 1,12 * * 5` | script/task 'draftBilling' tidak punya Task Template |
| 84 | aktif | mitra | updateTokenOpsigo | `*/59 * * * *` | script/task 'updateTokenOpsigo' tidak punya Task Template |
| 100 | aktif | mncTravel | updateTokenOpsigo | `*/59 3-23,0-1 * * *` | script/task 'updateTokenOpsigo' tidak punya Task Template |
| 115 | komentar | qa2 | kill_process | `*/7 * * * *` | script/task 'kill_process' tidak punya Task Template |
| 120 | komentar | qa2 | updateTokenOpsigo | `*/59 * * * *` | script/task 'updateTokenOpsigo' tidak punya Task Template |
| 122 | komentar | qa2 | postInvoiceVoid | `*/5 * * * *` | script/task 'postInvoiceVoid' tidak punya Task Template |
| 123 | komentar | qa2 | draftBilling | `0 1,12 * * 4` | script/task 'draftBilling' tidak punya Task Template |
| 133 | komentar | qa2 | kill_process | `*/7 * * * *` | script/task 'kill_process' tidak punya Task Template |
| 141 | komentar | qaTX | repost | `*/6 * * * *` | client 'qaTX' tidak ada di DB |
| 147 | komentar | wisman | update_token_bca | `*/50 * * * *` | script/task 'update_token_bca' tidak punya Task Template |
| 152 | aktif | wisman | updateTokenOpsigo | `*/59 * * * *` | script/task 'updateTokenOpsigo' tidak punya Task Template |
| 154 | komentar | wisman | draftBilling | `0 1,12 * * 5` | script/task 'draftBilling' tidak punya Task Template |
| 168 | komentar | mytour | update_token_bca | `*/50 * * * *` | script/task 'update_token_bca' tidak punya Task Template |
| 173 | aktif | mytour | updateTokenOpsigo | `*/59 * * * *` | script/task 'updateTokenOpsigo' tidak punya Task Template |
| 175 | komentar | mytour | draftBilling | `0 1,12 * * 5` | script/task 'draftBilling' tidak punya Task Template |
| 190 | aktif | anta | update_token_bca | `*/50 3-23,0-1 * * *` | script/task 'update_token_bca' tidak punya Task Template |
| 195 | aktif | anta | updateTokenOpsigo | `*/59 3-23,0-1 * * *` | script/task 'updateTokenOpsigo' tidak punya Task Template |
| 197 | komentar | anta | draftBilling | `0 1,12 * * 5` | script/task 'draftBilling' tidak punya Task Template |
| 214 | komentar | psa | update_token_bca | `*/50 * * * *` | script/task 'update_token_bca' tidak punya Task Template |
| 219 | aktif | psa | updateTokenOpsigo | `*/59 * * * *` | script/task 'updateTokenOpsigo' tidak punya Task Template |
| 221 | komentar | psa | draftBilling | `0 1,12 * * 5` | script/task 'draftBilling' tidak punya Task Template |
| 241 | aktif | gardi | updateTokenOpsigo | `*/59 * * * *` | script/task 'updateTokenOpsigo' tidak punya Task Template |
| 259 | aktif | pij | updateTokenOpsigo | `*/59 3-23,0-1 * * *` | script/task 'updateTokenOpsigo' tidak punya Task Template |
| 276 | komentar | agi | updateTokenOpsigo | `*/59 3-23,0-1 * * *` | script/task 'updateTokenOpsigo' tidak punya Task Template |
| 295 | aktif | globalwisata | updateTokenOpsigo | `*/59 3-23,0-1 * * *` | script/task 'updateTokenOpsigo' tidak punya Task Template |
| 314 | aktif | kiaxxxharmoni | updateTokenOpsigo | `*/59 3-23,0-1 * * *` | script/task 'updateTokenOpsigo' tidak punya Task Template |
| 332 | komentar | hitstravel | updateTokenOpsigo | `*/59 3-23,0-1 * * *` | script/task 'updateTokenOpsigo' tidak punya Task Template |
| 345 | aktif | aneka | updateTokenOpsigo | `*/59 3-23,0-1 * * *` | script/task 'updateTokenOpsigo' tidak punya Task Template |
| 363 | komentar | konverm | updateTokenOpsigo | `*/59 3-23,0-1 * * *` | script/task 'updateTokenOpsigo' tidak punya Task Template |
| 381 | komentar | mytrip | updateTokenOpsigo | `*/59 3-23,0-1 * * *` | script/task 'updateTokenOpsigo' tidak punya Task Template |
| 399 | aktif | jontru | updateTokenOpsigo | `*/59 3-23,0-1 * * *` | script/task 'updateTokenOpsigo' tidak punya Task Template |
| 406 | aktif | jontru | downloadFtpToS3Kai | `30 2 * * *` | script/task 'downloadFtpToS3Kai' tidak punya Task Template |
| 407 | aktif | jontru | syncFileToOpsifinKai | `0 3 * * *` | script/task 'syncFileToOpsifinKai' tidak punya Task Template |
| 419 | aktif | gns | update_token_bca | `*/50 * * * *` | script/task 'update_token_bca' tidak punya Task Template |
| 422 | aktif | gns | updateTokenOpsigo | `*/59 * * * *` | script/task 'updateTokenOpsigo' tidak punya Task Template |
| 439 | komentar | kiable | update_token_bca | `*/50 * * * *` | script/task 'update_token_bca' tidak punya Task Template |
| 442 | komentar | kiable | updateTokenOpsigo | `*/59 * * * *` | script/task 'updateTokenOpsigo' tidak punya Task Template |
| 458 | komentar | bravo | update_token_bca | `*/50 * * * *` | script/task 'update_token_bca' tidak punya Task Template |
| 461 | komentar | bravo | updateTokenOpsigo | `*/59 * * * *` | script/task 'updateTokenOpsigo' tidak punya Task Template |
| 478 | komentar | altorina | update_token_bca | `*/50 * * * *` | script/task 'update_token_bca' tidak punya Task Template |
| 481 | komentar | altorina | updateTokenOpsigo | `*/59 * * * *` | script/task 'updateTokenOpsigo' tidak punya Task Template |
| 497 | komentar | privela | update_token_bca | `*/50 * * * *` | script/task 'update_token_bca' tidak punya Task Template |
| 500 | komentar | altorina | updateTokenOpsigo | `*/59 * * * *` | script/task 'updateTokenOpsigo' tidak punya Task Template |
| 519 | komentar | altorina | updateTokenOpsigo | `*/59 * * * *` | script/task 'updateTokenOpsigo' tidak punya Task Template |

Ringkasan per script:

| Script | Aktif | Komentar |
| --- | ---: | ---: |
| `update_token_bca` | 3 | 8 |
| `updateTokenOpsigo` | 14 | 11 |
| `draftBilling` | 0 | 6 |
| `kill_process` | 0 | 2 |
| `postInvoiceVoid` | 0 | 1 |
| `repost` | 0 | 1 |
| `downloadFtpToS3Kai` | 1 | 0 |
| `syncFileToOpsifinKai` | 1 | 0 |

## Baris ganda untuk client+task yang sama

| Client | Task | Kandidat (baris: cron) | Dipilih |
| --- | --- | --- | --- |
| gn | recurring | L16 komentar: `0 5 * * *`<br>L18 aktif: `0 3 * * *` | L18 |
| aladin | recurring | L31 komentar: `0 1 * * *`<br>L33 komentar: `0 3 * * *` | L33 |
| kia | recurring | L62 komentar: `0 1 * * *`<br>L64 komentar: `0 3 * * *`<br>L324 komentar: `0 3 * * *` | L64 |
| tx | recurring | L75 komentar: `0 1 * * *`<br>L77 komentar: `0 3 * * *` | L77 |
| mitra | recurring | L90 komentar: `0 1 * * *`<br>L92 komentar: `0 3 * * *` | L92 |
| mnc_travel | recurring | L109 komentar: `0 1 * * *`<br>L111 komentar: `0 3 * * *` | L111 |
| qa1 | auto_summary | L130 komentar: `*/5 * * * *`<br>L252 komentar: `*/5 * * * *` | L130 |
| wisman | recurring | L160 komentar: `0 1 * * *`<br>L162 komentar: `0 3 * * *` | L162 |
| mytour | recurring | L181 komentar: `0 1 * * *`<br>L183 komentar: `0 3 * * *` | L183 |
| anta | recurring | L204 komentar: `0 1 * * *`<br>L206 komentar: `0 3 * * *` | L206 |
| psa | recurring | L227 komentar: `0 1 * * *`<br>L229 komentar: `0 3 * * *` | L227 |
| gardi | recurring | L249 aktif: `0 1 * * *`<br>L251 komentar: `0 3 * * *` | L249 |
| pij | recurring | L267 komentar: `0 1 * * *`<br>L269 komentar: `0 3 * * *` | L269 |
| agi | recurring | L284 komentar: `0 1 * * *`<br>L286 komentar: `0 3 * * *` | L286 |
| globalwisata | repost | L290 aktif: `*/2 3-23,0-1 * * *`<br>L291 komentar: `*/2 * * * *` | L290 |
| globalwisata | recurring | L303 komentar: `0 1 * * *`<br>L305 komentar: `0 3 * * *` | L305 |
| kiaxxxharmoni | repost | L309 aktif: `*/2 3-23,0-1 * * *`<br>L310 komentar: `*/2 * * * *` | L309 |
| hits_travel | repost | L327 komentar: `*/2 3-23,0-1 * * *`<br>L328 komentar: `*/2 * * * *` | L328 |
| aneka | repost | L340 aktif: `*/2 3-23,0-1 * * *`<br>L341 komentar: `*/2 * * * *` | L340 |
| aneka | recurring | L353 komentar: `0 1 * * *`<br>L355 komentar: `0 3 * * *` | L355 |
| konverm | repost | L358 aktif: `*/2 3-23,0-1 * * *`<br>L359 komentar: `*/2 * * * *` | L358 |
| mytrip | repost | L376 aktif: `*/2 3-23,0-1 * * *`<br>L377 komentar: `*/2 * * * *` | L376 |
| mytrip | recurring | L389 komentar: `0 1 * * *`<br>L391 komentar: `0 3 * * *` | L391 |
| jontru | repost | L394 aktif: `*/2 3-23,0-1 * * *`<br>L395 komentar: `*/2 * * * *` | L394 |
| gns | recurring | L430 komentar: `0 1 * * *`<br>L432 aktif: `0 3 * * *` | L432 |
| kiable | recurring | L450 komentar: `0 1 * * *`<br>L452 komentar: `0 3 * * *` | L452 |
| bravo | recurring | L469 komentar: `0 1 * * *`<br>L471 komentar: `0 3 * * *` | L471 |
| altorina | recurring | L489 komentar: `0 1 * * *`<br>L491 komentar: `0 3 * * *` | L491 |
| altorina | update_status_print_billing | L490 komentar: `0 0 * * *`<br>L509 komentar: `0 0 * * *`<br>L528 komentar: `0 0 * * *` | L490 |
| privela | recurring | L508 komentar: `0 1 * * *`<br>L510 komentar: `0 3 * * *` | L510 |
| excape | recurring | L527 komentar: `0 1 * * *`<br>L529 komentar: `0 3 * * *` | L529 |

## Schedule yang dibiarkan as is

275 schedule tidak punya baris di `crontab.txt`, sehingga cron dan metadata-nya
tidak diubah. Client `demo` dan `marvelous` tidak muncul sama sekali di `crontab.txt`.

| Client | Jumlah | Task |
| --- | ---: | --- |
| agi | 6 | auto_mail_credit_limit, auto_summary, kcic_email, repost_bca_api, repost_error, request_bca_api |
| aladin | 9 | auto_billing, auto_mail_sp, auto_summary, kcic_email, kill_process_timeout, post_invoice_to_opsigo, repost_bca_api, repost_error, request_bca_api |
| altorina | 6 | auto_billing, auto_mail_credit_limit, auto_mail_sp, auto_summary, kcic_email, repost_error |
| aneka | 7 | auto_billing, auto_mail_credit_limit, auto_summary, kcic_email, repost_bca_api, repost_error, request_bca_api |
| anta | 2 | auto_summary, kcic_email |
| bravo | 5 | auto_mail_credit_limit, auto_mail_sp, auto_summary, kcic_email, repost_error |
| demo | 20 | auto_billing, auto_mail_credit_limit, auto_mail_sp, auto_summary, billing_file, clear_inactive_temp_issued, clear_shared_folder_opsigo, e_invoice, kcic_email, kill_process_timeout, post_invoice_to_opsigo, recurring, repost, repost_bca_api, repost_error, request_bca_api, update_balance_trx, update_expired_user, update_print_no_hardcopy, update_status_print_billing |
| dev5 | 18 | auto_billing, auto_mail_credit_limit, auto_mail_sp, auto_summary, billing_file, clear_inactive_temp_issued, clear_shared_folder_opsigo, e_invoice, kcic_email, kill_process_timeout, post_invoice_to_opsigo, recurring, repost_bca_api, repost_error, request_bca_api, update_expired_user, update_print_no_hardcopy, update_status_print_billing |
| excape | 6 | auto_billing, auto_mail_credit_limit, auto_mail_sp, kcic_email, repost_error, update_status_print_billing |
| gardi | 7 | auto_billing, auto_mail_credit_limit, auto_summary, kcic_email, repost_bca_api, repost_error, request_bca_api |
| globalwisata | 6 | auto_mail_credit_limit, auto_summary, kcic_email, repost_bca_api, repost_error, request_bca_api |
| gn | 5 | auto_billing, auto_mail_credit_limit, auto_mail_sp, kcic_email, repost_error |
| gns | 5 | auto_billing, auto_mail_credit_limit, auto_mail_sp, kcic_email, repost_error |
| hits_travel | 11 | auto_billing, auto_mail_credit_limit, auto_summary, billing_file, clear_shared_folder_opsigo, kcic_email, recurring, repost_bca_api, repost_error, request_bca_api, update_status_print_billing |
| jontru | 4 | auto_billing, auto_mail_credit_limit, repost_bca_api, request_bca_api |
| kia | 10 | auto_billing, auto_mail_credit_limit, auto_mail_sp, auto_summary, e_invoice, kcic_email, post_invoice_to_opsigo, repost_bca_api, repost_error, request_bca_api |
| kiable | 6 | auto_billing, auto_mail_credit_limit, auto_mail_sp, auto_summary, kcic_email, repost_error |
| kiaxxxharmoni | 7 | auto_billing, auto_mail_credit_limit, auto_summary, kcic_email, repost_bca_api, repost_error, request_bca_api |
| konverm | 6 | auto_mail_credit_limit, auto_summary, kcic_email, repost_bca_api, repost_error, request_bca_api |
| marvelous | 20 | auto_billing, auto_mail_credit_limit, auto_mail_sp, auto_summary, billing_file, clear_inactive_temp_issued, clear_shared_folder_opsigo, e_invoice, kcic_email, kill_process_timeout, post_invoice_to_opsigo, recurring, repost, repost_bca_api, repost_error, request_bca_api, update_balance_trx, update_expired_user, update_print_no_hardcopy, update_status_print_billing |
| mitra | 8 | auto_mail_credit_limit, auto_mail_sp, auto_summary, e_invoice, kcic_email, repost_bca_api, repost_error, request_bca_api |
| mnc_travel | 6 | auto_mail_sp, auto_summary, kcic_email, repost_bca_api, repost_error, request_bca_api |
| mytour | 4 | auto_mail_credit_limit, auto_summary, kcic_email, repost_error |
| mytrip | 7 | auto_billing, auto_mail_credit_limit, auto_summary, kcic_email, repost_bca_api, repost_error, request_bca_api |
| pij | 7 | auto_billing, auto_mail_credit_limit, auto_summary, kcic_email, repost_bca_api, repost_error, request_bca_api |
| privela | 6 | auto_billing, auto_mail_credit_limit, auto_mail_sp, kcic_email, repost_error, update_status_print_billing |
| psa | 5 | auto_billing, auto_mail_credit_limit, auto_summary, kcic_email, repost_error |
| qa1 | 18 | auto_billing, auto_mail_credit_limit, auto_mail_sp, billing_file, clear_inactive_temp_issued, clear_shared_folder_opsigo, e_invoice, kcic_email, kill_process_timeout, post_invoice_to_opsigo, recurring, repost, repost_bca_api, repost_error, request_bca_api, update_expired_user, update_print_no_hardcopy, update_status_print_billing |
| qa2 | 9 | auto_billing, auto_mail_credit_limit, auto_summary, kcic_email, kill_process_timeout, recurring, repost_error, update_expired_user, update_print_no_hardcopy |
| rsp | 17 | auto_billing, auto_mail_credit_limit, auto_mail_sp, auto_summary, billing_file, clear_inactive_temp_issued, clear_shared_folder_opsigo, e_invoice, kcic_email, kill_process_timeout, post_invoice_to_opsigo, repost, repost_bca_api, repost_error, request_bca_api, update_print_no_hardcopy, update_status_print_billing |
| tx | 10 | auto_billing, auto_mail_credit_limit, auto_mail_sp, auto_summary, e_invoice, kcic_email, post_invoice_to_opsigo, repost_bca_api, repost_error, request_bca_api |
| vaya | 7 | auto_billing, auto_mail_credit_limit, auto_summary, billing_file, kcic_email, recurring, repost_error |
| wisman | 5 | auto_billing, auto_mail_credit_limit, auto_summary, kcic_email, repost_error |

## Cara menjalankan

1. Buka [`sql/crontab-sync-2026-10-01.sql`](sql/crontab-sync-2026-10-01.sql) dan cek
   blok per client.
2. Jalankan **LANGKAH 0** (pre-check) dan **LANGKAH 1** (backup ke
   `schedules_bak_20261001`). MySQL binary log sedang OFF, jadi backup ini satu-satunya jalan
   kembali.
3. Jalankan **LANGKAH 2** sampai `SELECT` verifikasi. Bila hasilnya sesuai,
   `COMMIT`; bila tidak, `ROLLBACK`.
4. LANGKAH 3 (#2959) opsional dan sebaiknya lewat UI.
5. Perubahan hanya data, tidak perlu `optimize:clear` atau restart worker.
   Cek hasilnya di menu Schedules.

## Keputusan terbuka

- **Timezone.** Cron disalin apa adanya dengan timezone `Asia/Jakarta`, sama
  seperti importer lama. Pola jam seperti `3-23,0-1` dan `21-23,0-1` di
  `crontab.txt` mengisyaratkan server lama berjalan di UTC. Bila benar,
  seluruh schedule berjam (baik yang sudah ada maupun hasil sync ini) bergeser
  7 jam dan perlu keputusan terpisah.
- **Enable.** Baris aktif di `crontab.txt` tidak meng-enable schedule. Mengaktifkan
  schedule butuh persetujuan eksplisit dan sebaiknya bertahap per client.
- **Script tanpa Task Template.** `updateTokenOpsigo` dan `update_token_bca` aktif
  di banyak client; `downloadFtpToS3Kai` dan `syncFileToOpsifinKai` aktif di
  jontru. Perlu diputuskan apakah dibuatkan Task Template.
- **Mapping `qa2` gateway → client `qa1`** dan **`kiaxxx` → `kia`** mengikuti
  config legacy; keduanya hanya muncul di baris komentar.
