<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DnsProvider extends Model
{
    use BelongsToOrganization, HasFactory;

    public const TYPE_CLOUDFLARE = 'cloudflare';

    public const TYPES = [
        self::TYPE_CLOUDFLARE,
    ];

    protected $fillable = [
        'name',
        'type',
        'api_token',
    ];

    protected $hidden = [
        'api_token',
    ];

    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
        ];
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }
}
