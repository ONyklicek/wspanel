<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirror of the real systemd state, synced from the agent (sys.services).
     * The panel never trusts this table for decisions — the agent does its own checks.
     */
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('unit')->unique();
            $table->string('type');
            $table->string('instance')->nullable(); // PHP version or site domain
            $table->string('worker')->nullable();
            $table->foreignId('site_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('load_state')->default('unknown');
            $table->string('active_state')->default('unknown');
            $table->string('sub_state')->nullable();
            $table->string('enabled_state')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
