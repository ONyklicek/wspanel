<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_servers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 64);
            $table->string('game', 32);
            $table->string('flavor', 32);
            $table->string('version', 32);
            $table->string('build', 32)->nullable();
            $table->unsignedInteger('port');
            $table->unsignedInteger('memory_mb');
            $table->unsignedInteger('cpu_percent')->default(0);
            $table->string('status', 16)->default('installing');
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique('port');
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedInteger('max_game_servers')->nullable();
            $table->unsignedInteger('max_game_memory_mb')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['max_game_servers', 'max_game_memory_mb']);
        });
        Schema::dropIfExists('game_servers');
    }
};
