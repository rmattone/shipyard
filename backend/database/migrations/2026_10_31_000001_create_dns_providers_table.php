<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DNS providers (Cloudflare for now) let ShipYard create the record for
     * a domain instead of the operator doing it by hand. The API token is
     * encrypted at rest. On domains, the dns_* columns remember which record
     * ShipYard points at the server: dns_record_managed is true only when
     * ShipYard created it, so removing the domain never deletes a record the
     * operator made. dns_proxied is the desired proxy state; while the
     * domain has no certificate yet the record stays DNS only and
     * dns_status is pending_proxy.
     */
    public function up(): void
    {
        Schema::create('dns_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 20);
            $table->text('api_token');
            $table->timestamps();
        });

        Schema::table('domains', function (Blueprint $table) {
            $table->foreignId('dns_provider_id')->nullable()->after('ssl_issuer')
                ->constrained('dns_providers')->nullOnDelete();
            $table->string('dns_zone_id', 64)->nullable()->after('dns_provider_id');
            $table->string('dns_record_id', 64)->nullable()->after('dns_zone_id');
            $table->boolean('dns_record_managed')->default(false)->after('dns_record_id');
            $table->boolean('dns_proxied')->default(false)->after('dns_record_managed');
            $table->string('dns_status', 20)->nullable()->after('dns_proxied');
            $table->text('dns_error')->nullable()->after('dns_status');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dns_provider_id');
            $table->dropColumn(['dns_zone_id', 'dns_record_id', 'dns_record_managed', 'dns_proxied', 'dns_status', 'dns_error']);
        });

        Schema::dropIfExists('dns_providers');
    }
};
