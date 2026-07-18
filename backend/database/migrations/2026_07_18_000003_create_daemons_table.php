<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Long-running processes (queue workers, custom daemons) managed as
     * systemd templated units on target servers. application_id nulls on
     * app delete rather than cascading: the row must outlive the app so
     * the removal job can still clean the units off the server.
     */
    public function up(): void
    {
        Schema::create('daemons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->nullable()->constrained()->nullOnDelete();
            $table->text('command');
            $table->string('user', 32)->default('www-data');
            $table->string('directory');
            $table->unsignedTinyInteger('processes')->default(1);
            $table->string('status', 20)->default('installing');
            $table->longText('log')->nullable();
            $table->timestamps();

            $table->index('server_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daemons');
    }
};
