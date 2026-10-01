<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class SetInstallationAdmin extends Command
{
    protected $signature = 'shipyard:installation-admin {user : Existing user ID} {--revoke : Remove installation administrator access}';

    protected $description = 'Grant or revoke installation administrator access from the trusted host console';

    public function handle(): int
    {
        $user = User::find($this->argument('user'));
        if (! $user) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $user->forceFill(['is_installation_admin' => ! $this->option('revoke')])->save();
        $this->info('Installation administrator access '.($this->option('revoke') ? 'revoked' : 'granted')." for {$user->email} (ID {$user->id}).");

        return self::SUCCESS;
    }
}
