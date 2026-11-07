<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hosting packages: the limits a client gets. A null limit means unlimited;
     * a client's effective limit is its package plus its own overrides, read live.
     */
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('description')->nullable();
            $table->unsignedInteger('max_sites')->nullable();
            $table->unsignedInteger('max_aliases')->nullable();
            $table->unsignedInteger('max_databases')->nullable();
            $table->unsignedInteger('max_db_size_mb')->nullable();
            $table->unsignedInteger('max_mailboxes')->nullable();
            $table->unsignedInteger('max_mail_quota_mb')->nullable();
            $table->unsignedInteger('disk_quota_mb')->nullable();
            $table->unsignedInteger('max_cron')->nullable();
            $table->unsignedInteger('max_workers')->nullable();
            $table->unsignedSmallInteger('fpm_children')->nullable();
            $table->string('ssh', 8)->default('shell');
            $table->json('allowed_modes')->nullable();
            $table->json('allowed_php')->nullable();
            $table->unsignedSmallInteger('backups_keep')->nullable();
            $table->boolean('offsite')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
