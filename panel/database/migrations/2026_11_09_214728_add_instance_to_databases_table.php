<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The site's dedicated server the database lives in (the agent's id; null = shared). */
    public function up(): void
    {
        Schema::table('databases', function (Blueprint $table) {
            $table->unsignedInteger('instance')->nullable()->after('site_id');
        });
    }

    public function down(): void
    {
        Schema::table('databases', function (Blueprint $table) {
            $table->dropColumn('instance');
        });
    }
};
