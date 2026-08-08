<?php

namespace App\Services;

use Cron\CronExpression;

/**
 * Menerjemahkan ekspresi cron ke bahasa Indonesia agar bisa dibaca operator
 * non-teknis. Menangani pola yang benar-benar dipakai di crontab Opsifin;
 * pola di luar itu jatuh ke deskripsi per-field yang tetap informatif.
 */
class CronDescriber
{
    private const DAYS = [
        0 => 'Minggu', 1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu',
        4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu',
    ];

    public function describe(string $expression): string
    {
        if (! CronExpression::isValidExpression($expression)) {
            return 'Ekspresi tidak valid.';
        }

        $fields = preg_split('/\s+/', trim($expression)) ?: [];

        if (count($fields) !== 5) {
            return 'Ekspresi tidak dikenali.';
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $fields;

        $parts = [$this->describeTime($minute, $hour)];

        if ($dayOfMonth !== '*') {
            $parts[] = 'pada tanggal '.$this->humanList($dayOfMonth);
        }

        if ($month !== '*') {
            $parts[] = 'di bulan '.$this->humanList($month);
        }

        if ($dayOfWeek !== '*') {
            $parts[] = 'setiap '.$this->describeDayOfWeek($dayOfWeek);
        }

        return ucfirst(implode(', ', $parts)).'.';
    }

    /**
     * Peringatan untuk `*​/N` yang jedanya tidak seragam (BUG-5 di rencana).
     */
    public function intervalWarning(string $expression): ?string
    {
        $minute = preg_split('/\s+/', trim($expression))[0] ?? '';

        if (! preg_match('#^\*/(\d+)$#', $minute, $m)) {
            return null;
        }

        $step = (int) $m[1];

        if ($step <= 0 || 60 % $step === 0) {
            return null;
        }

        return sprintf(
            'Jeda tidak seragam: berjalan di menit %s, jadi selisihnya %d menit lalu %d menit — '.
            'bukan "setiap %d menit".',
            implode(', ', range(0, 59, $step)),
            $step,
            60 % $step,
            $step,
        );
    }

    private function describeTime(string $minute, string $hour): string
    {
        if ($minute === '*' && $hour === '*') {
            return 'setiap menit';
        }

        if (preg_match('#^\*/(\d+)$#', $minute, $m)) {
            $every = 'setiap '.$m[1].' menit';

            return $hour === '*' ? $every : $every.' pada jam '.$this->humanList($hour);
        }

        if ($hour === '*') {
            return 'setiap jam pada menit '.$this->humanList($minute);
        }

        if (preg_match('#^\*/(\d+)$#', $hour, $m)) {
            return 'setiap '.$m[1].' jam pada menit '.$this->humanList($minute);
        }

        // Kasus paling umum: jam & menit tunggal.
        if (ctype_digit($minute) && ctype_digit($hour)) {
            return sprintf('setiap hari pukul %02d:%02d', (int) $hour, (int) $minute);
        }

        return 'pada jam '.$this->humanList($hour).' menit '.$this->humanList($minute);
    }

    private function describeDayOfWeek(string $field): string
    {
        $names = array_map(
            fn (string $value) => self::DAYS[(int) $value] ?? $value,
            $this->expand($field),
        );

        return $this->joinWithDan($names);
    }

    private function humanList(string $field): string
    {
        return $this->joinWithDan($this->expand($field));
    }

    /**
     * @return array<int, string>
     */
    private function expand(string $field): array
    {
        $values = [];

        foreach (explode(',', $field) as $part) {
            if (preg_match('/^(\d+)-(\d+)$/', $part, $m)) {
                $values[] = $m[1].'–'.$m[2];

                continue;
            }

            $values[] = $part;
        }

        return $values;
    }

    /**
     * @param  array<int, string>  $items
     */
    private function joinWithDan(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        $last = array_pop($items);

        return implode(', ', $items).' dan '.$last;
    }
}
