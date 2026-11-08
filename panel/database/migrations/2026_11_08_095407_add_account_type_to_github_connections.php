<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** User or Organization: what the connection shows and where its settings live on GitHub. */
    public function up(): void
    {
        Schema::table('github_connections', function (Blueprint $table) {
            $table->string('account_type', 20)->nullable()->after('login');
        });
    }

    public function down(): void
    {
        Schema::table('github_connections', function (Blueprint $table) {
            $table->dropColumn('account_type');
        });
    }
};
