<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('git_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['gitlab', 'github', 'bitbucket']);
            $table->string('host')->nullable(); // For self-hosted instances
            $table->text('access_token'); // Will be encrypted via model cast
            $table->string('username')->nullable(); // Required for Bitbucket
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('git_providers');
    }
};
