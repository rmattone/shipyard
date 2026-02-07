<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatabaseUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'database_id',
        'username',
        'password',
        'host',
        'privileges',
        'status',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'privileges' => 'array',
        ];
    }

    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
