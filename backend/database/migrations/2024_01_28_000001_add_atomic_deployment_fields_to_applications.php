<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->enum('deployment_strategy', ['in_place', 'atomic'])
                ->default('atomic')
                ->after('status');
            $table->unsignedTinyInteger('releases_to_keep')
                ->default(5)
                ->after('deployment_strategy');
            $table->json('shared_paths')
                ->nullable()
                ->after('releases_to_keep');
            $table->json('writable_paths')
                ->nullable()
                ->after('shared_paths');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn([
                'deployment_strategy',
                'releases_to_keep',
                'shared_paths',
                'writable_paths',
            ]);
        });
    }
};
