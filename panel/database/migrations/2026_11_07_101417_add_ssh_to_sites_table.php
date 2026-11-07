<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('ssh_access', 8)->default('off')->after('port'); // off | sftp | shell
            $table->text('ssh_keys')->nullable()->after('ssh_access');      // public keys, one per line
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['ssh_access', 'ssh_keys']);
        });
    }
};
