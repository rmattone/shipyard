<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional link between a scheduled task and the application it serves
     * (set by the app-scoped scheduler UI and the Laravel preset). Null on
     * app delete rather than cascade: the row must outlive the app so the
     * removal job can still clean the entry out of the remote crontab.
     */
    public function up(): void
    {
        Schema::table('scheduled_tasks', function (Blueprint $table) {
            $table->foreignId('application_id')
                ->nullable()
                ->after('server_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('application_id');
        });
    }
};
