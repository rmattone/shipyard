<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Periodic resource samples per server, written by servers:collect-metrics
     * and read by the metrics history endpoint. Raw values are stored in bytes
     * so the UI can format them; percentages are stored too so range queries
     * never divide. Rows cascade with the server and are pruned after
     * ServerMetric::RETENTION_DAYS.
     */
    public function up(): void
    {
        Schema::create('server_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->timestamp('collected_at');
            $table->decimal('cpu_percent', 5, 1);
            $table->unsignedSmallInteger('cpu_cores');
            $table->unsignedBigInteger('memory_total');
            $table->unsignedBigInteger('memory_used');
            $table->decimal('memory_percent', 5, 1);
            $table->unsignedBigInteger('swap_total');
            $table->unsignedBigInteger('swap_used');
            $table->unsignedBigInteger('disk_total');
            $table->unsignedBigInteger('disk_used');
            $table->decimal('disk_percent', 5, 1);
            $table->decimal('load_1', 6, 2);
            $table->decimal('load_5', 6, 2);
            $table->decimal('load_15', 6, 2);

            $table->index(['server_id', 'collected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_metrics');
    }
};
