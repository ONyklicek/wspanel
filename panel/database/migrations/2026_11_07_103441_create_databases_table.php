<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Databases of hosted sites. Each has an owner user of the same name;
     * passwords are never stored (they go into the site's env file via the agent).
     */
    public function up(): void
    {
        Schema::create('databases', function (Blueprint $table) {
            $table->id();
            $table->string('engine', 16);
            $table->string('name', 48);
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('backup_frequency', 8)->default('off');
            $table->unsignedSmallInteger('backup_keep')->default(7);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['engine', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('databases');
    }
};
