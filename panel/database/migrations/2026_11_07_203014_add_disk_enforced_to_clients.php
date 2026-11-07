<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Whether the server enforces the client's disk limit (group quota) or the panel only measures it. */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('disk_enforced')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('disk_enforced'));
    }
};
