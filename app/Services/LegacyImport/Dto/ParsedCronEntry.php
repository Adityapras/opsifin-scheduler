<?php

namespace App\Services\LegacyImport\Dto;

use App\Enums\LegacyPattern;

class ParsedCronEntry
{
    public function __construct(
        public int $lineNo,
        public string $rawLine,
        public bool $isCommented,
        public string $cronExpression,
        public string $command,
        public LegacyPattern $pattern,
        public ?string $clientKey,      // nama folder (direct) atau kode config (gateway)
        public ?string $taskKey,        // nama file script tanpa .sh (direct) atau task type (gateway)
        public bool $hasFlock = false,
        public ?string $lockFile = null,
        public ?string $sectionLabel = null,  // dari komentar "# -- Golden Nusa" di atasnya
    ) {}
}
