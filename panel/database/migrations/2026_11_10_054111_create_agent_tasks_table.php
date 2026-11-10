<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Long agent jobs the panel does not wait for: what runs, for whom, and
     * what to do once it ends (handler), whoever notices first.
     */
    public function up(): void
    {
        Schema::create('agent_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('key')->nullable()->index(); // a page's name for it (one running per key)
            $table->string('op', 64);
            $table->string('job_id', 64)->nullable();
            $table->string('title');
            $table->string('done_title');
            $table->text('body')->nullable();
            $table->string('status', 16)->default('running')->index(); // running, settling, done, failed
            $table->text('error')->nullable();
            $table->json('output')->nullable();
            $table->string('handler')->nullable(); // Class@method, called with the task once it ends
            $table->nullableMorphs('subject');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tasks');
    }
};
