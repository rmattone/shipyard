<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->enum('type', ['laravel', 'nodejs', 'static']);
            $table->string('domain');
            $table->string('gitlab_repo');
            $table->string('gitlab_branch')->default('main');
            $table->string('deploy_path');
            $table->string('build_command')->nullable();
            $table->json('post_deploy_commands')->nullable();
            $table->boolean('ssl_enabled')->default(false);
            $table->enum('status', ['active', 'deploying', 'failed'])->default('active');
            $table->string('webhook_secret');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
