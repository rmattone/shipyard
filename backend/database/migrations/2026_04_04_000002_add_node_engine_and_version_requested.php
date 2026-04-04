<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add 'node' to the engine enum
        DB::statement("ALTER TABLE database_installations MODIFY COLUMN engine ENUM('mysql', 'postgresql', 'pm2', 'php', 'node') NOT NULL");

        // Add version_requested column
        Schema::table('database_installations', function (Blueprint $table) {
            $table->string('version_requested')->nullable()->after('engine');
        });
    }

    public function down(): void
    {
        // Remove version_requested column
        Schema::table('database_installations', function (Blueprint $table) {
            $table->dropColumn('version_requested');
        });

        // Revert engine enum
        DB::statement("ALTER TABLE database_installations MODIFY COLUMN engine ENUM('mysql', 'postgresql', 'pm2', 'php') NOT NULL");
    }
};
