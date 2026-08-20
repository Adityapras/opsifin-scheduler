<?php

namespace App\Models;

use App\Enums\ExecutorType;
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
            'executor' => ExecutorType::class,
            'config' => 'array',
            'legacy_script_names' => 'array',
            'is_active' => 'boolean',
            'auto_assign_to_new_clients' => 'boolean',
            'default_schedule_enabled' => 'boolean',
            'default_prevent_overlap' => 'boolean',
            'legacy_gateway_routed' => 'boolean',
            'needs_review' => 'boolean',
        ];
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }
}
