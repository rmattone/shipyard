<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Null means the legacy /var/www layout; a value means the
            // home directory layout owned by this unix user.
            $table->string('deploy_user', 32)->nullable()->after('username');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('deploy_user');
        });
    }
};
