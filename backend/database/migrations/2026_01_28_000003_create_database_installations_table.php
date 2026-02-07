<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_installations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->enum('engine', ['mysql', 'postgresql']);
            $table->enum('status', ['pending', 'running', 'success', 'failed'])->default('pending');
            $table->longText('log')->nullable();
            $table->string('version_installed')->nullable();
            $table->text('admin_password')->nullable(); // encrypted at model level
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_installations');
    }
};
