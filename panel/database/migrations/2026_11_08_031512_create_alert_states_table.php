<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_states', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('level');
            $table->string('title');
            $table->text('detail')->nullable();
            $table->unsignedInteger('seen')->default(1); // consecutive checks it was there
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_states');
    }
};
