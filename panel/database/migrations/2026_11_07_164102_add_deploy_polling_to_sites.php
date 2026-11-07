<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('deploy_polled_ref')->nullable()->after('deploy_requested_ref');
            $table->timestamp('deploy_polled_at')->nullable()->after('deploy_polled_ref');
            $table->string('deploy_poll_error', 500)->nullable()->after('deploy_polled_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['deploy_polled_ref', 'deploy_polled_at', 'deploy_poll_error']);
        });
    }
};
