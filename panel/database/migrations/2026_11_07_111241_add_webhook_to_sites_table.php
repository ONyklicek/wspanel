<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('webhook_id', 32)->nullable()->unique()->after('ssh_keys'); // opaque, in the public URL
            $table->text('webhook_secret')->nullable()->after('webhook_id');           // encrypted at rest
            $table->boolean('webhook_enabled')->default(false)->after('webhook_secret');
            $table->timestamp('deploy_requested_at')->nullable()->after('webhook_enabled'); // a push arrived while a deploy was running
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['webhook_id', 'webhook_secret', 'webhook_enabled', 'deploy_requested_at']);
        });
    }
};
