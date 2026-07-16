<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * port: TCP port a Node.js app listens on (nginx proxy target and PM2
     * PORT env). Nullable; the application defaults to 3000.
     * php_version: per-app PHP-FPM version for the nginx fastcgi socket.
     * Nullable; falls back to the server's detected version, then 8.3.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->unsignedInteger('port')->nullable()->after('node_version');
            $table->string('php_version', 10)->nullable()->after('port');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['port', 'php_version']);
        });
    }
};
