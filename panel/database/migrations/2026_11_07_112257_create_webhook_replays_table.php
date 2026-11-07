<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replay protection: the sha256 of every accepted signed body, per site.
     * The signature covers the body, unlike any delivery-id header.
     * Kept 90 days (pruned by panel:sync), independent of the delivery log.
     */
    public function up(): void
    {
        Schema::create('webhook_replays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->char('body_hash', 64);
            $table->timestamp('created_at')->useCurrent()->index();
            $table->unique(['site_id', 'body_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_replays');
    }
};
