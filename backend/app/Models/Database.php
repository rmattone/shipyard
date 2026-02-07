<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Database extends Model
{
    use HasFactory;

    protected $fillable = [
        'server_id',
        'name',
        'type',
        'host',
        'port',
        'admin_user',
        'admin_password',
        'status',
        'charset',
        'collation',
    ];

    protected $hidden = [
        'admin_password',
    ];

    protected function casts(): array
    {
        return [
            'admin_password' => 'encrypted',
            'port' => 'integer',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(DatabaseUser::class);
    }

    public function isMySQL(): bool
    {
        return $this->type === 'mysql';
    }

    public function isPostgreSQL(): bool
    {
        return $this->type === 'postgresql';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function getDefaultPort(): int
    {
        return $this->isMySQL() ? 3306 : 5432;
    }
}
