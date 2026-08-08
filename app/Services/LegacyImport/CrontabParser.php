<?php

namespace App\Services\LegacyImport;

use App\Enums\LegacyPattern;
use App\Services\LegacyImport\Dto\ParsedCronEntry;

class CrontabParser
{
    /**
     * Baris komentar yang jelas-jelas dokumentasi bawaan crontab, bukan job.
     */
    private const NOISE_PREFIXES = [
        'Edit this file', 'Each task', 'indicating with', 'and what command',
        'To define', 'minute (m)', 'and day of week', 'Notice that', "daemon's notion",
        'Output of the', 'email to the user', 'For example', 'at 5 a.m', 'tar -zcf',
        'For more information', 'm h  dom',
    ];

    /**
     * @return array<int, ParsedCronEntry>
     */
    public function parse(string $contents): array
    {
        $entries = [];
        $section = null;
        $lines = preg_split('/\r?\n/', $contents) ?: [];

        foreach ($lines as $index => $line) {
            $lineNo = $index + 1;
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            $isCommented = str_starts_with($trimmed, '#');
            $body = trim(ltrim($trimmed, '#'));

            if ($body === '') {
                continue;
            }

            if (! preg_match('/^(\S+\s+\S+\s+\S+\s+\S+\s+\S+)\s+(.+)$/', $body, $m)) {
                if ($isCommented && ! $this->isNoise($body)) {
                    $section = $this->normalizeSection($body);
                }

                continue;
            }

            [, $expression, $command] = $m;

            if (! $this->looksLikeCronExpression($expression)) {
                if ($isCommented && ! $this->isNoise($body)) {
                    $section = $this->normalizeSection($body);
                }

                continue;
            }

            $entry = $this->buildEntry($lineNo, $line, $isCommented, $expression, trim($command), $section);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function buildEntry(
        int $lineNo,
        string $rawLine,
        bool $isCommented,
        string $expression,
        string $command,
        ?string $section,
    ): ?ParsedCronEntry {
        $hasFlock = str_contains($command, 'flock');
        $lockFile = null;

        if (preg_match('#(/\S+\.lock)#', $command, $m)) {
            $lockFile = $m[1];
        }

        // Pola B — gateway.sh <client> <task>
        if (preg_match('#gateway\.sh\s+(\S+)\s+(\S+)#', $command, $m)) {
            return new ParsedCronEntry(
                lineNo: $lineNo,
                rawLine: $rawLine,
                isCommented: $isCommented,
                cronExpression: $this->normalizeExpression($expression),
                command: $command,
                pattern: LegacyPattern::Gateway,
                clientKey: $m[1],
                taskKey: $m[2],
                hasFlock: $hasFlock,
                lockFile: $lockFile,
                sectionLabel: $section,
            );
        }

        // Pola A — /home/ubuntu/cron/<client>/<script>.sh
        if (preg_match('#/cron/([A-Za-z0-9_\-]+)/([A-Za-z0-9_\-\.]+)\.sh#', $command, $m)) {
            return new ParsedCronEntry(
                lineNo: $lineNo,
                rawLine: $rawLine,
                isCommented: $isCommented,
                cronExpression: $this->normalizeExpression($expression),
                command: $command,
                pattern: LegacyPattern::DirectScript,
                clientKey: $m[1],
                taskKey: $m[2],
                hasFlock: $hasFlock,
                lockFile: $lockFile,
                sectionLabel: $section,
            );
        }

        // Baris cron valid tapi bukan job Opsifin (mis. contoh bawaan) — dilaporkan sebagai unparsed.
        return new ParsedCronEntry(
            lineNo: $lineNo,
            rawLine: $rawLine,
            isCommented: $isCommented,
            cronExpression: $this->normalizeExpression($expression),
            command: $command,
            pattern: LegacyPattern::DirectScript,
            clientKey: null,
            taskKey: null,
            hasFlock: $hasFlock,
            lockFile: $lockFile,
            sectionLabel: $section,
        );
    }

    private function looksLikeCronExpression(string $expression): bool
    {
        foreach (preg_split('/\s+/', $expression) ?: [] as $field) {
            if (! preg_match('#^[\*\d,\-/]+$#', $field)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeExpression(string $expression): string
    {
        return implode(' ', preg_split('/\s+/', trim($expression)) ?: []);
    }

    private function isNoise(string $text): bool
    {
        foreach (self::NOISE_PREFIXES as $prefix) {
            if (str_starts_with($text, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeSection(string $text): string
    {
        return trim(preg_replace('/^[\-\s]+/', '', $text) ?: $text);
    }
}
