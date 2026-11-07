<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('node_version', 8)->nullable()->after('php_version');
            $table->unsignedInteger('port')->nullable()->after('node_version');
            $table->boolean('health_ok')->nullable()->after('status');
            $table->unsignedSmallInteger('health_status')->nullable()->after('health_ok');
            $table->string('health_error')->nullable()->after('health_status');
            $table->timestamp('health_checked_at')->nullable()->after('health_error');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['node_version', 'port', 'health_ok', 'health_status', 'health_error', 'health_checked_at']);
        });
    }
};
