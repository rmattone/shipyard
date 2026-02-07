<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('database_id')->constrained()->cascadeOnDelete();
            $table->string('username');
            $table->text('password');
            $table->string('host')->default('%');
            $table->json('privileges')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['database_id', 'username', 'host']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_users');
    }
};
