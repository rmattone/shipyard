<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();

            // No FK constraint yet: backup_configs does not exist until the
            // backups plan lands. Nullable because an uploaded restore has no
            // config to anchor to.
            $table->unsignedBigInteger('backup_config_id')->nullable()->index();

            $table->foreignId('database_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('kind', 10)->default('backup');    // backup | restore
            $table->string('trigger', 10)->default('cron');   // cron | manual
            $table->string('source', 10)->nullable();         // s3 | upload
            $table->string('status', 20);                     // pending | running | success | failed
            $table->string('failed_step', 20)->nullable();    // dump | upload | prune | restore
            $table->string('database_name');

            $table->string('s3_key')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('format', 10)->nullable();         // sql | sql_gz
            $table->string('upload_path')->nullable();
            $table->string('safety_dump_path')->nullable();

            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->longText('log')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['database_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
