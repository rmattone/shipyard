<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->text('private_key')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->text('private_key')->nullable(false)->change();
        });
    }
};
