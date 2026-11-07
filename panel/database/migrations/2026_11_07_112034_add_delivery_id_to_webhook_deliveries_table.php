<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The provider's delivery id: a replayed delivery is recognized and ignored. */
    public function up(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->string('delivery_id', 64)->nullable()->after('site_id');
            $table->unique(['site_id', 'delivery_id']);
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropUnique(['site_id', 'delivery_id']);
            $table->dropColumn('delivery_id');
        });
    }
};
