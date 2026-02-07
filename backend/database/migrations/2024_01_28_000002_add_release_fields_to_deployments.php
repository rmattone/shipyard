<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->string('release_id', 14)
                ->nullable()
                ->after('application_id');
            $table->string('release_path')
                ->nullable()
                ->after('release_id');
            $table->boolean('is_active')
                ->default(false)
                ->after('release_path');
            $table->enum('type', ['deploy', 'rollback'])
                ->default('deploy')
                ->after('is_active');
            $table->foreignId('rollback_target_id')
                ->nullable()
                ->after('type')
                ->constrained('deployments')
                ->nullOnDelete();
        });

        // Add index for quick lookup of active deployments
        Schema::table('deployments', function (Blueprint $table) {
            $table->index(['application_id', 'is_active']);
            $table->index('release_id');
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropIndex(['application_id', 'is_active']);
            $table->dropIndex(['release_id']);
            $table->dropForeign(['rollback_target_id']);
            $table->dropColumn([
                'release_id',
                'release_path',
                'is_active',
                'type',
                'rollback_target_id',
            ]);
        });
    }
};
