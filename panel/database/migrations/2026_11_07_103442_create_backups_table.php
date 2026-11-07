<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Mirror of the dump files the agent keeps under /var/backups/wspanel. */
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('database_id')->constrained()->cascadeOnDelete();
            $table->string('file');
            $table->unsignedBigInteger('size');
            $table->timestamp('taken_at');
            $table->timestamps();
            $table->unique(['database_id', 'file']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
