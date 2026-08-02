<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    // current_organization_id is intentionally not fillable: it is only
    // ever set through the switch endpoint after a membership check.
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Issue the SPA login token. The expires_at column is stamped explicitly
     * (not left to Sanctum's config-window check) because the SSE stream
     * controllers authenticate query-string tokens by reading the column.
     */
    public function issueAuthToken(): string
    {
        $minutes = config('sanctum.expiration');

        return $this->createToken(
            'auth-token',
            ['*'],
            $minutes ? now()->addMinutes((int) $minutes) : null
        )->plainTextToken;
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    public function roleIn(Organization|int $organization): ?string
    {
        $organizationId = $organization instanceof Organization ? $organization->id : $organization;

        return $this->organizations()
            ->whereKey($organizationId)
            ->first()
            ?->pivot
            ->role;
    }

    public function belongsToOrganization(Organization|int $organization): bool
    {
        $organizationId = $organization instanceof Organization ? $organization->id : $organization;

        return $this->organizations()->whereKey($organizationId)->exists();
    }
}
