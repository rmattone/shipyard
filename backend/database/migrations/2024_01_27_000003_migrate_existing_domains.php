<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Migrate existing application domains to the new domains table
        $applications = DB::table('applications')
            ->whereNotNull('domain')
            ->where('domain', '!=', '')
            ->get();

        foreach ($applications as $application) {
            DB::table('domains')->insert([
                'application_id' => $application->id,
                'domain' => $application->domain,
                'is_primary' => true,
                'ssl_enabled' => $application->ssl_enabled,
                'ssl_expires_at' => null,
                'ssl_issuer' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Remove all domains that were migrated (primary domains)
        // Note: This will remove the domain records but keep the applications.domain field intact
        DB::table('domains')->where('is_primary', true)->delete();
    }
};
