<?php

namespace App\Models;

use App\Enums\HttpMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskTemplate extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'http_method' => HttpMethod::class,
            'headers' => 'array',
            'legacy_script_names' => 'array',
            'is_active' => 'boolean',
            'legacy_gateway_routed' => 'boolean',
            'needs_review' => 'boolean',
        ];
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(ClientTaskOverride::class);
    }
}
