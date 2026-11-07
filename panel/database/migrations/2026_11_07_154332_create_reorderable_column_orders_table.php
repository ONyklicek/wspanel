<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reorderable_column_orders', function (Blueprint $table) {
            $table->id();

            // Match the user model's key type so non-integer (UUID/ULID) auth
            // keys are supported. Indexed (not FK-constrained) to stay portable
            // across custom user tables and connections.
            match (config('wire-sortable.user_key_type', 'id')) {
                'uuid' => $table->uuid('user_id'),
                'ulid' => $table->ulid('user_id'),
                default => $table->unsignedBigInteger('user_id'),
            };
            $table->index('user_id');

            $table->string('model_type');
            $table->string('table_identifier');
            $table->json('column_order');
            $table->timestamps();

            // Explicit short name: the auto-generated identifier exceeds MySQL's
            // 64-character limit for index names.
            $table->unique(['user_id', 'model_type', 'table_identifier'], 'reorderable_column_orders_owner_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reorderable_column_orders');
    }
};
