<?php

namespace App\Support;

use App\Models\Organization;

/**
 * Request-scoped tenant context. Set exclusively by the
 * SetOrganizationContext HTTP middleware; queue workers and artisan
 * commands never bind it, so they run unscoped and SerializesModels
 * restoration keeps working (Laravel's Context facade is deliberately
 * avoided: it dehydrates into job payloads and would re-apply the
 * tenant scope inside workers, silently "losing" models).
 *
 * Not Octane-safe as-is: a long-lived worker would leak the previous
 * request's organization. Fine under php-fpm, which resets per request.
 */
final class CurrentOrganization
{
    private static ?Organization $organization = null;

    private static ?string $role = null;

    public static function set(Organization $organization, string $role): void
    {
        self::$organization = $organization;
        self::$role = $role;
    }

    public static function get(): ?Organization
    {
        return self::$organization;
    }

    public static function id(): ?int
    {
        return self::$organization?->id;
    }

    public static function role(): ?string
    {
        return self::$role;
    }

    public static function forget(): void
    {
        self::$organization = null;
        self::$role = null;
    }
}
