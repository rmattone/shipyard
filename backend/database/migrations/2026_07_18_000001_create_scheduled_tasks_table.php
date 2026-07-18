<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->text('command');
            $table->string('user', 32)->default('root');
            $table->string('frequency', 20);
            $table->string('minute', 30)->nullable();
            $table->string('hour', 30)->nullable();
            $table->string('day', 30)->nullable();
            $table->string('month', 30)->nullable();
            $table->string('weekday', 30)->nullable();
            $table->string('status', 20)->default('installing');
            $table->longText('log')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'user']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_tasks');
    }
};
