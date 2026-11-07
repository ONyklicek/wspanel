<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('tls_mode')->default('auto')->after('mail_mode'); // auto | internal | custom
        });
        Schema::table('certificates', function (Blueprint $table) {
            $table->string('mode')->default('auto')->after('domain');
        });
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn('tls_mode'));
        Schema::table('certificates', fn (Blueprint $table) => $table->dropColumn('mode'));
    }
};
