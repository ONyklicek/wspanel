<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('ci_id', 32)->nullable()->unique()->after('deploy_requested_ref');
            $table->string('ci_token_hash', 64)->nullable()->after('ci_id'); // sha256 of the token, shown once
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropUnique(['ci_id']);
            $table->dropColumn(['ci_id', 'ci_token_hash']);
        });
    }
};
