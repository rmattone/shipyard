<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PHP major.minor version detected on the server (set during PHP
     * installation and software checks). Used to template the PHP-FPM
     * socket path in nginx configs instead of hardcoding 8.3.
     */
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('php_version', 10)->nullable()->after('is_local');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('php_version');
        });
    }
};
