-- =====================================================================
-- Sinkronisasi schedules dari crontab.txt (2026-10-01)
-- Dokumen pendamping: docs/crontab-sync-2026-10-01.md
--
-- Yang diubah  : cron_expression + metadata legacy_* untuk 31 schedule.
-- Yang TIDAK   : is_enabled, prevent_overlap, timezone, next_run_at,
--                schedule tanpa baris di crontab.txt (dibiarkan as is).
-- Guard        : setiap UPDATE mensyaratkan cron_expression masih sama
--                dengan snapshot 2026-10-01; bila sudah diubah lewat UI,
--                UPDATE itu mengenai 0 row (tidak menimpa).
-- Binlog OFF   : jalankan LANGKAH 1 (backup) sebelum apa pun.
-- =====================================================================

-- ---------------------------------------------------------------------
-- LANGKAH 0 — Pre-check: harus mengembalikan 31 row.
-- ---------------------------------------------------------------------
SELECT COUNT(*) AS expected_31
FROM schedules
WHERE id IN (3156, 3347, 3348, 3349, 3350, 3351, 3353, 3354, 3355, 3356, 3357, 3359, 3360, 3361, 3362, 3056, 3453, 2866, 3459, 3326, 3329, 3331, 3333, 3334, 3336, 3340, 3341, 3342, 3475, 3501, 3549);

-- ---------------------------------------------------------------------
-- LANGKAH 1 — Backup row yang akan disentuh (termasuk #2959 opsional).
-- ---------------------------------------------------------------------
CREATE TABLE schedules_bak_20261001 AS
SELECT * FROM schedules WHERE id IN (3156, 3347, 3348, 3349, 3350, 3351, 3353, 3354, 3355, 3356, 3357, 3359, 3360, 3361, 3362, 3056, 3453, 2866, 3459, 3326, 3329, 3331, 3333, 3334, 3336, 3340, 3341, 3342, 3475, 3501, 3549, 2959);

SELECT COUNT(*) AS backed_up FROM schedules_bak_20261001;

-- ---------------------------------------------------------------------
-- LANGKAH 2 — Update (31 statement). Total affected rows harus 31.
-- ---------------------------------------------------------------------
START TRANSACTION;

-- ===== aneka =====
-- #3156 aneka / repost: [cron_expression, legacy_was_commented]
-- DB : */2 * * * * (komentar)
-- TXT: */2 3-23,0-1 * * * (aktif) — crontab.txt baris 340
UPDATE schedules SET
    cron_expression = '*/2 3-23,0-1 * * *',
    legacy_pattern = 'direct_script',
    legacy_line_no = 340,
    legacy_command = '/home/ubuntu/cron/aneka/repost.sh',
    legacy_was_commented = 0,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3156 AND client_id = 284 AND task_template_id = 457
  AND cron_expression = '*/2 * * * *';

-- ===== excape =====
-- #3347 excape / auto_summary: [legacy_pattern, legacy_command, legacy_was_commented]
-- DB : */5 * * * * (manual, tanpa metadata legacy)
-- TXT: */5 * * * * (komentar) — crontab.txt baris 530
UPDATE schedules SET
    cron_expression = '*/5 * * * *',
    legacy_pattern = 'gateway',
    legacy_line_no = 530,
    legacy_command = '/home/ubuntu/cron/gateway.sh excape auto_summary',
    legacy_was_commented = 1,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3347 AND client_id = 323 AND task_template_id = 448
  AND cron_expression = '*/5 * * * *';

-- #3348 excape / billing_file: [cron_expression, legacy_pattern, legacy_command, legacy_was_commented]
-- DB : */5 * * * * (manual, tanpa metadata legacy)
-- TXT: */10 * * * * (komentar) — crontab.txt baris 526
UPDATE schedules SET
    cron_expression = '*/10 * * * *',
    legacy_pattern = 'gateway',
    legacy_line_no = 526,
    legacy_command = '/home/ubuntu/cron/gateway.sh excape billing_file',
    legacy_was_commented = 1,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3348 AND client_id = 323 AND task_template_id = 449
  AND cron_expression = '*/5 * * * *';

-- #3349 excape / clear_inactive_temp_issued: [cron_expression, legacy_pattern, legacy_command]
-- DB : */5 * * * * (manual, tanpa metadata legacy)
-- TXT: 2 5 * * * (aktif) — crontab.txt baris 524
UPDATE schedules SET
    cron_expression = '2 5 * * *',
    legacy_pattern = 'gateway',
    legacy_line_no = 524,
    legacy_command = '/home/ubuntu/cron/gateway.sh excape clear_inactive_temp_issued',
    legacy_was_commented = 0,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3349 AND client_id = 323 AND task_template_id = 450
  AND cron_expression = '*/5 * * * *';

-- #3350 excape / clear_shared_folder_opsigo: [cron_expression, legacy_pattern, legacy_command]
-- DB : */5 * * * * (manual, tanpa metadata legacy)
-- TXT: 3 5 * * * (aktif) — crontab.txt baris 525
UPDATE schedules SET
    cron_expression = '3 5 * * *',
    legacy_pattern = 'gateway',
    legacy_line_no = 525,
    legacy_command = '/home/ubuntu/cron/gateway.sh excape clear_shared_folder_opsigo',
    legacy_was_commented = 0,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3350 AND client_id = 323 AND task_template_id = 451
  AND cron_expression = '*/5 * * * *';

-- #3351 excape / e_invoice: [cron_expression, legacy_pattern, legacy_command, legacy_had_flock, legacy_lock_file]
-- DB : */5 * * * * (manual, tanpa metadata legacy)
-- TXT: */5 22-23,0-3 * * * (aktif) — crontab.txt baris 521
UPDATE schedules SET
    cron_expression = '*/5 22-23,0-3 * * *',
    legacy_pattern = 'gateway',
    legacy_line_no = 521,
    legacy_command = '/usr/bin/flock -n /tmp/excape_e_invoice.lock /home/ubuntu/cron/gateway.sh excape e_invoice',
    legacy_was_commented = 0,
    legacy_had_flock = 1,
    legacy_lock_file = '/tmp/excape_e_invoice.lock',
    updated_at = NOW()
WHERE id = 3351 AND client_id = 323 AND task_template_id = 452
  AND cron_expression = '*/5 * * * *';

-- #3353 excape / kill_process_timeout: [cron_expression, legacy_pattern, legacy_command, legacy_was_commented]
-- DB : */5 * * * * (manual, tanpa metadata legacy)
-- TXT: */1 * * * * (komentar) — crontab.txt baris 516
UPDATE schedules SET
    cron_expression = '*/1 * * * *',
    legacy_pattern = 'gateway',
    legacy_line_no = 516,
    legacy_command = '/home/ubuntu/cron/gateway.sh excape kill_process_timeout',
    legacy_was_commented = 1,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3353 AND client_id = 323 AND task_template_id = 454
  AND cron_expression = '*/5 * * * *';

-- #3354 excape / post_invoice_to_opsigo: [legacy_pattern, legacy_command, legacy_was_commented]
-- DB : */5 * * * * (manual, tanpa metadata legacy)
-- TXT: */5 * * * * (komentar) — crontab.txt baris 520
UPDATE schedules SET
    cron_expression = '*/5 * * * *',
    legacy_pattern = 'gateway',
    legacy_line_no = 520,
    legacy_command = '/home/ubuntu/cron/gateway.sh excape post_invoice_to_opsigo',
    legacy_was_commented = 1,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3354 AND client_id = 323 AND task_template_id = 455
  AND cron_expression = '*/5 * * * *';

-- #3355 excape / recurring: [cron_expression, legacy_pattern, legacy_command, legacy_was_commented]
-- DB : */5 * * * * (manual, tanpa metadata legacy)
-- TXT: 0 3 * * * (komentar) — crontab.txt baris 529
UPDATE schedules SET
    cron_expression = '0 3 * * *',
    legacy_pattern = 'gateway',
    legacy_line_no = 529,
    legacy_command = '/bin/bash /home/ubuntu/cron/gateway.sh excape recurring',
    legacy_was_commented = 1,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3355 AND client_id = 323 AND task_template_id = 456
  AND cron_expression = '*/5 * * * *';

-- #3356 excape / repost: [legacy_pattern, legacy_command]
-- DB : */5 * * * * (manual, tanpa metadata legacy)
-- TXT: */5 * * * * (aktif) — crontab.txt baris 514
UPDATE schedules SET
    cron_expression = '*/5 * * * *',
    legacy_pattern = 'gateway',
    legacy_line_no = 514,
    legacy_command = '/home/ubuntu/cron/gateway.sh excape repost',
    legacy_was_commented = 0,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3356 AND client_id = 323 AND task_template_id = 457
  AND cron_expression = '*/5 * * * *';

-- #3357 excape / repost_bca_api: [cron_expression, legacy_pattern, legacy_command, legacy_was_commented]
-- DB : */5 * * * * (manual, tanpa metadata legacy)
-- TXT: 10 5 * * * (komentar) — crontab.txt baris 518
UPDATE schedules SET
    cron_expression = '10 5 * * *',
    legacy_pattern = 'gateway',
    legacy_line_no = 518,
    legacy_command = '/home/ubuntu/cron/gateway.sh excape repost_bca_api',
    legacy_was_commented = 1,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3357 AND client_id = 323 AND task_template_id = 458
  AND cron_expression = '*/5 * * * *';

-- #3359 excape / request_bca_api: [cron_expression, legacy_pattern, legacy_command, legacy_was_commented]
-- DB : */5 * * * * (manual, tanpa metadata legacy)
-- TXT: 0 5 * * * (komentar) — crontab.txt baris 517
UPDATE schedules SET
    cron_expression = '0 5 * * *',
    legacy_pattern = 'gateway',
    legacy_line_no = 517,
    legacy_command = '/home/ubuntu/cron/gateway.sh excape request_bca_api',
    legacy_was_commented = 1,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3359 AND client_id = 323 AND task_template_id = 460
  AND cron_expression = '*/5 * * * *';

-- #3360 excape / update_balance_trx: [cron_expression, legacy_pattern, legacy_command]
-- DB : */5 * * * * (manual, tanpa metadata legacy)
-- TXT: */7 * * * * (aktif) — crontab.txt baris 515
UPDATE schedules SET
    cron_expression = '*/7 * * * *',
    legacy_pattern = 'gateway',
    legacy_line_no = 515,
    legacy_command = '/home/ubuntu/cron/gateway.sh excape update_balance_trx',
    legacy_was_commented = 0,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3360 AND client_id = 323 AND task_template_id = 461
  AND cron_expression = '*/5 * * * *';

-- #3361 excape / update_expired_user: [cron_expression, legacy_pattern, legacy_command]
-- DB : */5 * * * * (manual, tanpa metadata legacy)
-- TXT: 1 5 * * * (aktif) — crontab.txt baris 523
UPDATE schedules SET
    cron_expression = '1 5 * * *',
    legacy_pattern = 'gateway',
    legacy_line_no = 523,
    legacy_command = '/home/ubuntu/cron/gateway.sh excape update_expired_user',
    legacy_was_commented = 0,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3361 AND client_id = 323 AND task_template_id = 462
  AND cron_expression = '*/5 * * * *';

-- #3362 excape / update_print_no_hardcopy: [cron_expression, legacy_pattern, legacy_command]
-- DB : */5 * * * * (manual, tanpa metadata legacy)
-- TXT: */2 * * * * (aktif) — crontab.txt baris 522
UPDATE schedules SET
    cron_expression = '*/2 * * * *',
    legacy_pattern = 'gateway',
    legacy_line_no = 522,
    legacy_command = '/home/ubuntu/cron/gateway.sh excape update_print_no_hardcopy',
    legacy_was_commented = 0,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3362 AND client_id = 323 AND task_template_id = 463
  AND cron_expression = '*/5 * * * *';

-- ===== gardi =====
-- #3056 gardi / recurring: [cron_expression, legacy_pattern, legacy_command, legacy_was_commented]
-- DB : 0 3 * * * (komentar)
-- TXT: 0 1 * * * (aktif) — crontab.txt baris 249
UPDATE schedules SET
    cron_expression = '0 1 * * *',
    legacy_pattern = 'direct_script',
    legacy_line_no = 249,
    legacy_command = '/home/ubuntu/cron/gardi/recuring.sh',
    legacy_was_commented = 0,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3056 AND client_id = 288 AND task_template_id = 456
  AND cron_expression = '0 3 * * *';

-- ===== gn =====
-- #3453 gn / auto_summary: [cron_expression, legacy_pattern, legacy_command, legacy_was_commented]
-- DB : */10 1,4-5 * * * (manual, tanpa metadata legacy)
-- TXT: */5 * * * * (komentar) — crontab.txt baris 19
UPDATE schedules SET
    cron_expression = '*/5 * * * *',
    legacy_pattern = 'gateway',
    legacy_line_no = 19,
    legacy_command = '/home/ubuntu/cron/gateway.sh gnj auto_summary',
    legacy_was_commented = 1,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3453 AND client_id = 290 AND task_template_id = 448
  AND cron_expression = '*/10 1,4-5 * * *';

-- #2866 gn / recurring: [legacy_pattern, legacy_command, legacy_was_commented]
-- DB : 0 3 * * * (komentar)
-- TXT: 0 3 * * * (aktif) — crontab.txt baris 18
UPDATE schedules SET
    cron_expression = '0 3 * * *',
    legacy_pattern = 'gateway',
    legacy_line_no = 18,
    legacy_command = '/bin/bash /home/ubuntu/cron/gateway.sh gnj recurring',
    legacy_was_commented = 0,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 2866 AND client_id = 290 AND task_template_id = 456
  AND cron_expression = '0 3 * * *';

-- ===== gns =====
-- #3459 gns / auto_summary: [legacy_pattern, legacy_command, legacy_was_commented]
-- DB : */5 * * * * (manual, tanpa metadata legacy)
-- TXT: */5 * * * * (komentar) — crontab.txt baris 433
UPDATE schedules SET
    cron_expression = '*/5 * * * *',
    legacy_pattern = 'gateway',
    legacy_line_no = 433,
    legacy_command = '/home/ubuntu/cron/gateway.sh gns auto_summary',
    legacy_was_commented = 1,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3459 AND client_id = 291 AND task_template_id = 448
  AND cron_expression = '*/5 * * * *';

-- ===== hits_travel =====
-- #3326 hits_travel / auto_mail_sp: [cron_expression, legacy_pattern, legacy_command, legacy_was_commented]
-- DB : */5 * * * * (manual, tanpa metadata legacy)
-- TXT: 0 21 * * * (komentar) — crontab.txt baris 335
UPDATE schedules SET
    cron_expression = '0 21 * * *',
    legacy_pattern = 'direct_script',
    legacy_line_no = 335,
    legacy_command = '/home/ubuntu/cron/hitstravel/autoMailSP.sh',
    legacy_was_commented = 1,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3326 AND client_id = 322 AND task_template_id = 447
  AND cron_expression = '*/5 * * * *';

-- #3329 hits_travel / clear_inactive_temp_issued: [cron_expression, legacy_pattern, legacy_command, legacy_was_commented]
-- DB : */5 * * * * (manual, tanpa metadata legacy)
-- TXT: 0 0 * * * (komentar) — crontab.txt baris 337
UPDATE schedules SET
    cron_expression = '0 0 * * *',
    legacy_pattern = 'direct_script',
    legacy_line_no = 337,
    legacy_command = '/home/ubuntu/cron/hitstravel/clearInactiveTempIssued.sh',
    legacy_was_commented = 1,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3329 AND client_id = 322 AND task_template_id = 450
  AND cron_expression = '*/5 * * * *';

-- #3331 hits_travel / e_invoice: [cron_expression, legacy_pattern, legacy_command, legacy_was_commented]
-- DB : */5 * * * * (manual, tanpa metadata legacy)
-- TXT: */5 22-23,0-3 * * * (komentar) — crontab.txt baris 336
UPDATE schedules SET
    cron_expression = '*/5 22-23,0-3 * * *',
    legacy_pattern = 'direct_script',
    legacy_line_no = 336,
    legacy_command = '/home/ubuntu/cron/hitstravel/e_invoice.sh',
    legacy_was_commented = 1,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3331 AND client_id = 322 AND task_template_id = 452
  AND cron_expression = '*/5 * * * *';

-- #3333 hits_travel / kill_process_timeout: [cron_expression, legacy_pattern, legacy_command, legacy_was_commented]
-- DB : */5 * * * * (manual, tanpa metadata legacy)
-- TXT: */1 6-18 * * * (komentar) — crontab.txt baris 330
UPDATE schedules SET
    cron_expression = '*/1 6-18 * * *',
    legacy_pattern = 'direct_script',
    legacy_line_no = 330,
    legacy_command = '/home/ubuntu/cron/hitstravel/kill_process_timeout.sh',
    legacy_was_commented = 1,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3333 AND client_id = 322 AND task_template_id = 454
  AND cron_expression = '*/5 * * * *';

-- #3334 hits_travel / post_invoice_to_opsigo: [cron_expression, legacy_pattern, legacy_command, legacy_was_commented]
-- DB : */5 * * * * (manual, tanpa metadata legacy)
-- TXT: */5 3-23,0-1 * * * (komentar) — crontab.txt baris 331
UPDATE schedules SET
    cron_expression = '*/5 3-23,0-1 * * *',
    legacy_pattern = 'direct_script',
    legacy_line_no = 331,
    legacy_command = '/home/ubuntu/cron/hitstravel/postInvoice.sh',
    legacy_was_commented = 1,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3334 AND client_id = 322 AND task_template_id = 455
  AND cron_expression = '*/5 * * * *';

-- #3336 hits_travel / repost: [cron_expression, legacy_pattern, legacy_command, legacy_was_commented]
-- DB : */5 * * * * (manual, tanpa metadata legacy)
-- TXT: */2 * * * * (komentar) — crontab.txt baris 328
UPDATE schedules SET
    cron_expression = '*/2 * * * *',
    legacy_pattern = 'direct_script',
    legacy_line_no = 328,
    legacy_command = '/home/ubuntu/cron/hitstravel/repost.sh',
    legacy_was_commented = 1,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3336 AND client_id = 322 AND task_template_id = 457
  AND cron_expression = '*/5 * * * *';

-- #3340 hits_travel / update_balance_trx: [cron_expression, legacy_pattern, legacy_command, legacy_was_commented]
-- DB : */5 * * * * (manual, tanpa metadata legacy)
-- TXT: */7 3-23,0-1 * * * (komentar) — crontab.txt baris 329
UPDATE schedules SET
    cron_expression = '*/7 3-23,0-1 * * *',
    legacy_pattern = 'direct_script',
    legacy_line_no = 329,
    legacy_command = '/home/ubuntu/cron/hitstravel/update_balance_trx.sh',
    legacy_was_commented = 1,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3340 AND client_id = 322 AND task_template_id = 461
  AND cron_expression = '*/5 * * * *';

-- #3341 hits_travel / update_expired_user: [cron_expression, legacy_pattern, legacy_command, legacy_was_commented]
-- DB : */5 * * * * (manual, tanpa metadata legacy)
-- TXT: 0 21 * * * (komentar) — crontab.txt baris 334
UPDATE schedules SET
    cron_expression = '0 21 * * *',
    legacy_pattern = 'direct_script',
    legacy_line_no = 334,
    legacy_command = '/home/ubuntu/cron/hitstravel/updateExpiredUser.sh',
    legacy_was_commented = 1,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3341 AND client_id = 322 AND task_template_id = 462
  AND cron_expression = '*/5 * * * *';

-- #3342 hits_travel / update_print_no_hardcopy: [cron_expression, legacy_pattern, legacy_command, legacy_was_commented]
-- DB : */5 * * * * (manual, tanpa metadata legacy)
-- TXT: */2 6-18 * * * (komentar) — crontab.txt baris 333
UPDATE schedules SET
    cron_expression = '*/2 6-18 * * *',
    legacy_pattern = 'direct_script',
    legacy_line_no = 333,
    legacy_command = '/home/ubuntu/cron/hitstravel/updatePrintNoHardCopy.sh',
    legacy_was_commented = 1,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3342 AND client_id = 322 AND task_template_id = 463
  AND cron_expression = '*/5 * * * *';

-- ===== jontru =====
-- #3475 jontru / auto_summary: [legacy_pattern, legacy_command, legacy_was_commented]
-- DB : */5 * * * * (manual, tanpa metadata legacy)
-- TXT: */5 * * * * (komentar) — crontab.txt baris 413
UPDATE schedules SET
    cron_expression = '*/5 * * * *',
    legacy_pattern = 'gateway',
    legacy_line_no = 413,
    legacy_command = '/home/ubuntu/cron/gateway.sh jontru auto_summary',
    legacy_was_commented = 1,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3475 AND client_id = 295 AND task_template_id = 448
  AND cron_expression = '*/5 * * * *';

-- ===== konverm =====
-- #3501 konverm / auto_billing: [cron_expression, legacy_pattern, legacy_command, legacy_was_commented, legacy_had_flock, legacy_lock_file]
-- DB : */5 * * * * (manual, tanpa metadata legacy)
-- TXT: */10 1,4-5 * * * (komentar) — crontab.txt baris 373
UPDATE schedules SET
    cron_expression = '*/10 1,4-5 * * *',
    legacy_pattern = 'gateway',
    legacy_line_no = 373,
    legacy_command = '/usr/bin/flock -n /tmp/konverm_auto_billing.lock /home/ubuntu/cron/gateway.sh konverm auto_billing',
    legacy_was_commented = 1,
    legacy_had_flock = 1,
    legacy_lock_file = '/tmp/konverm_auto_billing.lock',
    updated_at = NOW()
WHERE id = 3501 AND client_id = 299 AND task_template_id = 445
  AND cron_expression = '*/5 * * * *';

-- ===== privela =====
-- #3549 privela / auto_summary: [legacy_pattern, legacy_command, legacy_was_commented]
-- DB : */5 * * * * (manual, tanpa metadata legacy)
-- TXT: */5 * * * * (komentar) — crontab.txt baris 511
UPDATE schedules SET
    cron_expression = '*/5 * * * *',
    legacy_pattern = 'gateway',
    legacy_line_no = 511,
    legacy_command = '/home/ubuntu/cron/gateway.sh privela auto_summary',
    legacy_was_commented = 1,
    legacy_had_flock = 0,
    legacy_lock_file = NULL,
    updated_at = NOW()
WHERE id = 3549 AND client_id = 319 AND task_template_id = 448
  AND cron_expression = '*/5 * * * *';

-- Verifikasi sebelum COMMIT: harus 31.
SELECT COUNT(*) AS updated_31
FROM schedules s
JOIN schedules_bak_20261001 b ON b.id = s.id
WHERE s.id IN (3156, 3347, 3348, 3349, 3350, 3351, 3353, 3354, 3355, 3356, 3357, 3359, 3360, 3361, 3362, 3056, 3453, 2866, 3459, 3326, 3329, 3331, 3333, 3334, 3336, 3340, 3341, 3342, 3475, 3501, 3549)
  AND NOT (s.cron_expression <=> b.cron_expression
       AND s.legacy_pattern <=> b.legacy_pattern
       AND s.legacy_command <=> b.legacy_command
       AND s.legacy_was_commented <=> b.legacy_was_commented
       AND s.legacy_had_flock <=> b.legacy_had_flock
       AND s.legacy_lock_file <=> b.legacy_lock_file);

-- Bila angka sesuai: COMMIT. Bila tidak: ROLLBACK.
COMMIT;
-- ROLLBACK;

-- ---------------------------------------------------------------------
-- LANGKAH 3 (OPSIONAL) — Schedule yang sedang ENABLED.
-- #2959 qa2/repost sedang live ke QA2 dengan 1-59/10 (diubah sengaja
-- 25 Sep 2026). crontab.txt memakai 1-59/5 = trafik dua kali lipat.
-- Disarankan ubah lewat menu Schedules (UI) agar next_run_at dihitung
-- ulang. Bila tetap lewat SQL: next_run_at lama dipakai sekali, lalu
-- dispatcher menghitung occurrence berikutnya dengan cron baru.
-- Hapus tanda komentar hanya bila memang ingin diterapkan.
-- ---------------------------------------------------------------------
-- #2959 qa2 / repost: [cron_expression]
-- DB : 1-59/10 * * * * (aktif)
-- TXT: 1-59/5 * * * * (aktif) — crontab.txt baris 119
-- UPDATE schedules SET
--     cron_expression = '1-59/5 * * * *',
--     legacy_pattern = 'direct_script',
--     legacy_line_no = 119,
--     legacy_command = '/home/ubuntu/cron/qa2/repost.sh',
--     legacy_was_commented = 0,
--     legacy_had_flock = 0,
--     legacy_lock_file = NULL,
--     updated_at = NOW()
-- WHERE id = 2959 AND client_id = 308 AND task_template_id = 457
--   AND cron_expression = '1-59/10 * * * *';

-- ---------------------------------------------------------------------
-- ROLLBACK SETELAH COMMIT — kembalikan dari tabel backup.
-- ---------------------------------------------------------------------
-- UPDATE schedules s
-- JOIN schedules_bak_20261001 b ON b.id = s.id
-- SET s.cron_expression = b.cron_expression,
--     s.legacy_pattern = b.legacy_pattern,
--     s.legacy_line_no = b.legacy_line_no,
--     s.legacy_command = b.legacy_command,
--     s.legacy_was_commented = b.legacy_was_commented,
--     s.legacy_had_flock = b.legacy_had_flock,
--     s.legacy_lock_file = b.legacy_lock_file,
--     s.updated_at = b.updated_at;

-- Setelah yakin tidak perlu rollback:
-- DROP TABLE schedules_bak_20261001;
