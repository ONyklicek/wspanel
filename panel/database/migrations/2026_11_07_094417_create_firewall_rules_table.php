<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Mirror of UFW rules, synced from the agent (fw.list). */
    public function up(): void
    {
        Schema::create('firewall_rules', function (Blueprint $table) {
            $table->id();
            $table->string('to');
            $table->unsignedInteger('port')->nullable();
            $table->string('proto', 8)->nullable();
            $table->string('action', 8);
            $table->string('from')->default('Anywhere');
            $table->string('comment')->nullable();
            $table->boolean('ipv4')->default(true);
            $table->boolean('ipv6')->default(false);
            $table->boolean('managed')->default(false);
            $table->string('description')->nullable(); // panel-only note, kept across syncs
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['to', 'action', 'from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firewall_rules');
    }
};
