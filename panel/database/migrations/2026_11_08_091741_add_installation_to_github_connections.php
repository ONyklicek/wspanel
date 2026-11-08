<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A connection is either a token or an installation of the panel's GitHub App (no token stored). */
    public function up(): void
    {
        Schema::table('github_connections', function (Blueprint $table) {
            $table->text('token')->nullable()->change();
            $table->unsignedBigInteger('installation_id')->nullable()->after('login');
        });
    }

    public function down(): void
    {
        Schema::table('github_connections', function (Blueprint $table) {
            $table->dropColumn('installation_id');
        });
    }
};
