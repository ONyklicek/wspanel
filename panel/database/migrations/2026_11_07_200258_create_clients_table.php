<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hosting clients. Each owns sites, databases and DNS zones, signs in with
     * one or more users of role "client" and maps to a Linux group on the server.
     */
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128);
            $table->string('company', 128)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->foreignId('package_id')->nullable()->constrained()->nullOnDelete();
            $table->json('overrides')->nullable();
            $table->string('linux_group', 32)->nullable()->unique();
            $table->timestamp('suspended_at')->nullable();
            $table->json('suspended_sites')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
