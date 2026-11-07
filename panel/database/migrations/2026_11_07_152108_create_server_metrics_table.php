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
        Schema::create('server_metrics', function (Blueprint $table) {
            $table->id();
            $table->timestamp('recorded_at')->index();
            $table->float('cpu');          // % of all cores, averaged over the minute
            $table->float('iowait');
            $table->float('load1');
            $table->unsignedBigInteger('mem_used');
            $table->unsignedBigInteger('mem_total');
            $table->unsignedBigInteger('swap_used');
            $table->unsignedBigInteger('net_rx');   // bytes/s
            $table->unsignedBigInteger('net_tx');
            $table->unsignedBigInteger('io_read');  // bytes/s
            $table->unsignedBigInteger('io_write');
            $table->float('disk_percent');  // fullest filesystem
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_metrics');
    }
};
