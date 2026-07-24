<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-server SSH public keys installed into a unix user's
     * authorized_keys file on the target server. fingerprint is the
     * sha256 hex digest of the decoded key blob, used to detect
     * duplicates and to reconcile against what's actually on the server.
     */
    public function up(): void
    {
        Schema::create('server_ssh_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('public_key');
            $table->string('fingerprint', 64);
            $table->string('username', 32);
            $table->string('status', 20)->default('installing');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'username', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_ssh_keys');
    }
};
