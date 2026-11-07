<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The site's own web-server rules (Caddy syntax), as the agent stored them. */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->text('web_rules')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn('web_rules'));
    }
};
