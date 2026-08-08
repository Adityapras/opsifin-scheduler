<?php

namespace App\Services\LegacyImport;

/**
 * Parser untuk file bergaya `KEY='value'` — configs/*.conf dan opsifin_env.sh.
 */
class ShellConfigParser
{
    /**
     * @return array<string, string>
     */
    public function parse(string $contents): array
    {
        $result = [];

        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $line = preg_replace('/^export\s+/', '', $line) ?? $line;

            if (! preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
                continue;
            }

            $result[$m[1]] = $this->unquote(trim($m[2]));
        }

        return $result;
    }

    private function unquote(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];

            if (($first === "'" && $last === "'") || ($first === '"' && $last === '"')) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }
}
