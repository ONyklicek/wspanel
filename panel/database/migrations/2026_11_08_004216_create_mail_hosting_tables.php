<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mail hosting: domains the server receives mail for, their mailboxes and
     * aliases (the agent's mail.json is the source on the server; these mirror
     * what the panel sent). Passwords are never stored.
     */
    public function up(): void
    {
        Schema::create('mail_domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('mailboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_domain_id')->constrained()->cascadeOnDelete();
            $table->string('local_part', 64);
            $table->unsignedInteger('quota_mb')->default(1024);
            $table->boolean('enabled')->default(true);
            $table->json('autoreply')->nullable();
            $table->unsignedBigInteger('used_kb')->nullable();
            $table->timestamps();
            $table->unique(['mail_domain_id', 'local_part']);
        });
        Schema::create('mail_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_domain_id')->constrained()->cascadeOnDelete();
            $table->string('local_part', 64); // '' = catch-all
            $table->json('destinations');
            $table->timestamps();
            $table->unique(['mail_domain_id', 'local_part']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_aliases');
        Schema::dropIfExists('mailboxes');
        Schema::dropIfExists('mail_domains');
    }
};
