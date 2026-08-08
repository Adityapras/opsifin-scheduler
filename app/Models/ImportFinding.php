<?php

namespace App\Models;

use App\Enums\FindingSeverity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportFinding extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'severity' => FindingSeverity::class,
            'context' => 'array',
            'resolved' => 'boolean',
        ];
    }

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class);
    }
}
