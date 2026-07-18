<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Release IDs gained a random suffix ("YmdHis-xxxxxx", 21 chars) to
     * prevent collisions between deployments created in the same second.
     * The column was sized exactly to the old bare-timestamp format.
     */
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->string('release_id', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->string('release_id', 14)->nullable()->change();
        });
    }
};
