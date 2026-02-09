<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Server extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'host',
        'port',
        'username',
        'private_key',
        'status',
        'is_local',
    ];

    protected $hidden = [
        'private_key',
    ];

    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'port' => 'integer',
            'is_local' => 'boolean',
        ];
    }

    public function isLocal(): bool
    {
        return $this->is_local;
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    public function databases(): HasMany
    {
        return $this->hasMany(Database::class);
    }

    public function databaseInstallations(): HasMany
    {
        return $this->hasMany(DatabaseInstallation::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
