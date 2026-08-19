<?php

namespace App\Models;

use App\Enums\AuthType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['auth_secret', 'auth_secret_key'];

    protected function casts(): array
    {
        return [
            'auth_type' => AuthType::class,
            'is_active' => 'boolean',
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

    /**
     * Nilai header Authorization untuk client ini.
     */
    public function authorizationHeader(): ?string
    {
        return match ($this->auth_type) {
            AuthType::Basic => 'Basic '.base64_encode($this->auth_username.':'.$this->auth_secret),
            AuthType::Bearer => 'Bearer '.$this->auth_secret,
            AuthType::None, null => null,
        };
    }
}
