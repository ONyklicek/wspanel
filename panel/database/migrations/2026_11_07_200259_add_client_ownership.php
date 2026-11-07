<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who owns what. Users are admins (everything) or clients (their own
     * resources); a null client_id on a resource means the admin owns it.
     * Child records (cron jobs, deploys, backups) follow their parent.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 16)->default('admin');
            $table->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
        });
        foreach (['sites', 'databases', 'dns_zones'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['sites', 'databases', 'dns_zones', 'users'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('client_id');
            });
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('role'));
    }
};
