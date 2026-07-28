<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Server extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    public const LEGACY_DEPLOY_BASE = '/var/www/shipyard';

    /**
     * How long a trashed server stays restorable before servers:purge-trashed
     * force-deletes it. Child rows only cascade on the force delete, so until
     * then a restore brings back a fully wired server.
     */
    public const TRASH_RETENTION_DAYS = 30;

    protected $fillable = [
        'name',
        'host',
        'port',
        'username',
        'deploy_user',
        'private_key',
        'status',
        'is_local',
        'php_version',
    ];

    protected $hidden = [
        'private_key',
    ];

    protected $appends = ['default_deploy_base', 'purges_at'];

    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'port' => 'integer',
            'is_local' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    public function isLocal(): bool
    {
        return $this->is_local;
    }

    /**
     * The unix user PHP executes as on this server: the deploy user on
     * home-layout servers, www-data otherwise. Values are safe to embed
     * unescaped in generated scripts because every deploy_user write path
     * validates against ServerUserService::USER_PATTERN.
     */
    public function phpRuntimeUser(): string
    {
        return $this->deploy_user ?? 'www-data';
    }

    /**
     * Base directory new applications default into. Servers with a
     * provisioned deploy user use the home directory layout.
     */
    public function getDefaultDeployBaseAttribute(): string
    {
        return filled($this->deploy_user)
            ? "/home/{$this->deploy_user}"
            : self::LEGACY_DEPLOY_BASE;
    }

    /**
     * When servers:purge-trashed will force-delete this server, or null while
     * it is not trashed. Exposed so the frontend never needs to know the
     * retention period.
     */
    public function getPurgesAtAttribute(): ?string
    {
        return $this->deleted_at?->copy()->addDays(self::TRASH_RETENTION_DAYS)->toIso8601String();
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

    public function scheduledTasks(): HasMany
    {
        return $this->hasMany(ScheduledTask::class);
    }

    public function daemons(): HasMany
    {
        return $this->hasMany(Daemon::class);
    }

    public function sshKeys(): HasMany
    {
        return $this->hasMany(ServerSshKey::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
