<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->foreignId('git_provider_id')
                ->nullable()
                ->after('server_id')
                ->constrained('git_providers')
                ->nullOnDelete();

            $table->renameColumn('gitlab_repo', 'repository_url');
            $table->renameColumn('gitlab_branch', 'branch');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->renameColumn('repository_url', 'gitlab_repo');
            $table->renameColumn('branch', 'gitlab_branch');

            $table->dropConstrainedForeignId('git_provider_id');
        });
    }
};
