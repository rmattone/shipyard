<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Steal is time the hypervisor gave to another guest. It used to be folded
 * into cpu_percent, which reported a provider overselling the host as the
 * customer's own CPU usage. It now has its own column so the two can be told
 * apart. Rows written before this migration keep a zero here, and their
 * cpu_percent still includes whatever steal they saw.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_metrics', function (Blueprint $table) {
            $table->decimal('cpu_steal_percent', 5, 1)->default(0)->after('cpu_percent');
        });
    }

    public function down(): void
    {
        Schema::table('server_metrics', function (Blueprint $table) {
            $table->dropColumn('cpu_steal_percent');
        });
    }
};
