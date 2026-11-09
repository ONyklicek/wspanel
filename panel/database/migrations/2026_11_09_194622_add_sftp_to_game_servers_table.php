<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_servers', function (Blueprint $table) {
            $table->boolean('sftp')->default(false)->after('cpu_percent');
            $table->text('sftp_keys')->nullable()->after('sftp');
            $table->boolean('sftp_password')->default(false)->after('sftp_keys');
        });
    }

    public function down(): void
    {
        Schema::table('game_servers', function (Blueprint $table) {
            $table->dropColumn(['sftp', 'sftp_keys', 'sftp_password']);
        });
    }
};
