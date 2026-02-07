<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->onDelete('cascade');
            $table->string('domain')->unique();
            $table->boolean('is_primary')->default(false);
            $table->boolean('ssl_enabled')->default(false);
            $table->timestamp('ssl_expires_at')->nullable();
            $table->string('ssl_issuer')->nullable();
            $table->timestamps();

            $table->index('application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
