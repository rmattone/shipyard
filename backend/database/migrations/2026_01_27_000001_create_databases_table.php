<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('databases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['mysql', 'postgresql']);
            $table->string('host')->default('localhost');
            $table->unsignedSmallInteger('port');
            $table->string('admin_user');
            $table->text('admin_password');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->string('charset')->nullable();
            $table->string('collation')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('databases');
    }
};
