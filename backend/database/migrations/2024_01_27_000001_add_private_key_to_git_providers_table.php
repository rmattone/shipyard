<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('git_providers', function (Blueprint $table) {
            // Add private_key column after access_token
            $table->text('private_key')->nullable()->after('access_token');
        });

        // Make access_token nullable (since SSH key is an alternative)
        Schema::table('git_providers', function (Blueprint $table) {
            $table->text('access_token')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('git_providers', function (Blueprint $table) {
            $table->dropColumn('private_key');
        });

        Schema::table('git_providers', function (Blueprint $table) {
            $table->text('access_token')->nullable(false)->change();
        });
    }
};
