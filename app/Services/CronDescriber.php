<?php

namespace App\Services;

use Cron\CronExpression;

/**
 * Menerjemahkan ekspresi cron ke kalimat biasa agar bisa dibaca operator
 * non-teknis. Menangani pola yang benar-benar dipakai di crontab Opsifin;
 * pola di luar itu jatuh ke deskripsi per-field yang tetap informatif.
 */
class CronDescriber
{
    private const DAYS = [
        0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
        4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday',
    ];

    private const MONTHS = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ];

    public function describe(string $expression): string
    {
        if (! CronExpression::isValidExpression($expression)) {
            return 'Invalid expression.';
        }

        $fields = preg_split('/\s+/', trim($expression)) ?: [];

        if (count($fields) !== 5) {
            return 'Unrecognised expression.';
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $fields;

        $parts = [$this->describeTime($minute, $hour)];

        if ($dayOfMonth !== '*') {
            $parts[] = 'on day '.$this->humanList($dayOfMonth);
        }

        if ($month !== '*') {
            $parts[] = 'in '.$this->describeMonth($month);
        }

        if ($dayOfWeek !== '*') {
            $parts[] = 'on '.$this->describeDayOfWeek($dayOfWeek);
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
            'Uneven interval: it runs at minutes %s, so the gap is %d minutes and then %d minutes — '.
            'not "every %d minutes".',
            implode(', ', range(0, 59, $step)),
            $step,
            60 % $step,
            $step,
        );
    }

    private function describeTime(string $minute, string $hour): string
    {
        if ($minute === '*' && $hour === '*') {
            return 'every minute';
        }

        if (preg_match('#^\*/(\d+)$#', $minute, $m)) {
            $every = 'every '.$this->pluralise((int) $m[1], 'minute');

            return $hour === '*' ? $every : $every.' during hour '.$this->humanList($hour);
        }

        if ($hour === '*') {
            return 'every hour at minute '.$this->humanList($minute);
        }

        if (preg_match('#^\*/(\d+)$#', $hour, $m)) {
            return 'every '.$this->pluralise((int) $m[1], 'hour').' at minute '.$this->humanList($minute);
        }

        // Kasus paling umum: jam & menit tunggal.
        if (ctype_digit($minute) && ctype_digit($hour)) {
            return sprintf('every day at %02d:%02d', (int) $hour, (int) $minute);
        }

        return 'at hour '.$this->humanList($hour).', minute '.$this->humanList($minute);
    }

    private function describeDayOfWeek(string $field): string
    {
        return $this->joinWithAnd(array_map(
            fn (string $value) => self::DAYS[(int) $value] ?? $value,
            $this->expand($field),
        ));
    }

    private function describeMonth(string $field): string
    {
        return $this->joinWithAnd(array_map(
            fn (string $value) => ctype_digit($value) ? (self::MONTHS[(int) $value] ?? $value) : $value,
            $this->expand($field),
        ));
    }

    private function humanList(string $field): string
    {
        return $this->joinWithAnd($this->expand($field));
    }

    private function pluralise(int $count, string $noun): string
    {
        return $count === 1 ? $noun : $count.' '.$noun.'s';
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
    private function joinWithAnd(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }
}
