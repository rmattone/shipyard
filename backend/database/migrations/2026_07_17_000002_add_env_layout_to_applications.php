<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Preserves the .env file's structure (comments, blank lines, key order)
// across panel edits; values stay in environment_variables.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->json('env_layout')->nullable()->after('post_deploy_commands');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('env_layout');
        });
    }
};
