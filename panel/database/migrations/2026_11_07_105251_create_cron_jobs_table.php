<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Mirror of a site's scheduled tasks (systemd timers managed by the agent). */
    public function up(): void
    {
        Schema::create('cron_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('agent_id');
            $table->string('kind', 16);
            $table->json('args');
            $table->string('schedule', 100);
            $table->string('calendar')->nullable();
            $table->string('last_run')->nullable();
            $table->string('last_result', 32)->nullable();
            $table->string('next_run')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'agent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cron_jobs');
    }
};
