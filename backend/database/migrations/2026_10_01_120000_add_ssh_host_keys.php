<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['servers', 'git_providers'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->text('ssh_host_key')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['servers', 'git_providers'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn('ssh_host_key'));
        }
    }
};
