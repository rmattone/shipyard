<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE database_installations MODIFY COLUMN engine ENUM('mysql', 'postgresql', 'pm2') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE database_installations MODIFY COLUMN engine ENUM('mysql', 'postgresql') NOT NULL");
    }
};
