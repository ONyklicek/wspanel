<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_traffic_hourly', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->dateTime('hour');
            $table->unsignedBigInteger('requests')->default(0);
            $table->unsignedBigInteger('bytes')->default(0);
            $table->unsignedInteger('s2xx')->default(0);
            $table->unsignedInteger('s3xx')->default(0);
            $table->unsignedInteger('s4xx')->default(0);
            $table->unsignedInteger('s5xx')->default(0);
            $table->unsignedInteger('bots')->default(0);
            $table->unsignedBigInteger('total_ms')->default(0);
            $table->json('top'); // paths, ips, referers: value => count
            $table->unique(['site_id', 'hour']);
        });
        // Uptime: every check of the site's backend (each minute).
        Schema::create('site_health_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->dateTime('checked_at');
            $table->boolean('ok');
            $table->unsignedSmallInteger('status')->nullable();
            $table->unsignedInteger('ms')->nullable();
            $table->index(['site_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_health_checks');
        Schema::dropIfExists('site_traffic_hourly');
    }
};
