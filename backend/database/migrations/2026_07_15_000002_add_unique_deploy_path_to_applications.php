<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two applications on the same server must not share a deploy path, or
     * their deployments overwrite each other and deleting one with
     * delete_files wipes the survivor's files. The controller enforces this,
     * and this index is the backstop against races.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->unique(['server_id', 'deploy_path']);
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropUnique(['server_id', 'deploy_path']);
        });
    }
};
