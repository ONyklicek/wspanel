<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // email | slack | discord | telegram
            $table->string('name');
            $table->text('config'); // encrypted: address, webhook URL, bot token + chat
            $table->json('events'); // problems | resolved | deploys
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channels');
    }
};
