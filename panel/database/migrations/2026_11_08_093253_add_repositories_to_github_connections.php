<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * For an app installation: the repositories its panel user administers on
     * GitHub (checked with their own token when connecting). The installation
     * token can do more; the panel uses it for these only.
     */
    public function up(): void
    {
        Schema::table('github_connections', function (Blueprint $table) {
            $table->json('repositories')->nullable()->after('installation_id');
        });
    }

    public function down(): void
    {
        Schema::table('github_connections', function (Blueprint $table) {
            $table->dropColumn('repositories');
        });
    }
};
