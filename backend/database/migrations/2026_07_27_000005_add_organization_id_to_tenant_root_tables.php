<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant root tables: everything else (applications, deployments,
     * databases, ...) reaches an organization through its server or
     * application parent, so only these three get the column.
     *
     * restrictOnDelete (not cascade): deleting an organization must never
     * silently drop servers; the API refuses to delete non-empty orgs.
     *
     * Backfill uses the query builder, not models, so this migration
     * stays valid regardless of future model changes or global scopes.
     */
    private const TABLES = ['servers', 'git_providers', 'notification_channels'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('organization_id')
                    ->nullable()
                    ->after('id')
                    ->constrained()
                    ->restrictOnDelete();
            });
        }

        $this->backfill();

        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('organization_id')->nullable(false)->change();
            });
        }
    }

    private function backfill(): void
    {
        $hasTenantRows = collect(self::TABLES)
            ->contains(fn (string $tableName) => DB::table($tableName)->exists());

        $hasUsers = DB::table('users')->exists();

        if (! $hasUsers) {
            if ($hasTenantRows) {
                throw new RuntimeException(
                    'Cannot backfill organization_id: tenant data exists but there are no users '
                    .'to own the default organization. Create the admin user first (php artisan db:seed).'
                );
            }

            // Fresh install: nothing to backfill, the seeder creates the first org.
            return;
        }

        $organizationId = DB::table('organizations')->insertGetId([
            'name' => 'Default Organization',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Every pre-existing user was implicitly an admin of everything.
        DB::table('users')->orderBy('id')->each(function ($user) use ($organizationId) {
            DB::table('organization_user')->insert([
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'role' => 'owner',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        DB::table('users')->update(['current_organization_id' => $organizationId]);

        foreach (self::TABLES as $tableName) {
            DB::table($tableName)->update(['organization_id' => $organizationId]);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('organization_id');
            });
        }
    }
};
