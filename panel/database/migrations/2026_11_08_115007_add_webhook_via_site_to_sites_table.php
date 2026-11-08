<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Whether the site's edge carries its deploy webhook route (as last set on the agent). */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('webhook_via_site')->default(false)->after('webhook_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('webhook_via_site');
        });
    }
};
