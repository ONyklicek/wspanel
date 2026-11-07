<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A site's own Valkey server (settings as the agent stored them), and the
     * most memory a package allows one (null = any, 0 = none).
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->json('valkey')->nullable();
        });
        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedInteger('max_valkey_mb')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn('valkey'));
        Schema::table('packages', fn (Blueprint $table) => $table->dropColumn('max_valkey_mb'));
    }
};
