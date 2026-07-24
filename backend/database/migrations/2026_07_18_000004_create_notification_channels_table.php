<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notification channels (Discord webhook, Telegram bot, Resend email)
     * the admin configures in Settings. Per-channel config holds the
     * secrets (webhook URL, bot token, API key) and is encrypted at rest;
     * events is the list of event keys the channel subscribes to.
     */
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('name');
            $table->text('config');
            $table->json('events');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channels');
    }
};
