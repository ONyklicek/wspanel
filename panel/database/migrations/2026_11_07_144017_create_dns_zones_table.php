<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('dns_zones', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->string('provider'); // local | cloudflare
            $table->string('cloudflare_zone_id')->nullable();
            $table->text('cloudflare_token')->nullable(); // encrypted
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dns_zones');
    }
};
