<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GitHub accounts connected by a token, sites linked to a repository
     * through one (deploy key and webhook created over the API), and the
     * GitHub deployment each deploy is reported as.
     */
    public function up(): void
    {
        Schema::create('github_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('login');
            $table->text('token'); // encrypted
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('github_connection_id')->nullable()->after('ci_token_hash')->constrained()->nullOnDelete();
            $table->string('github_repo')->nullable()->after('github_connection_id'); // owner/name
            $table->unsignedBigInteger('github_hook_id')->nullable()->after('github_repo');
            $table->unsignedBigInteger('github_key_id')->nullable()->after('github_hook_id');
            $table->string('github_environment', 100)->nullable()->after('github_key_id');
            $table->timestamp('github_linked_at')->nullable()->after('github_environment');
        });
        Schema::table('deployments', function (Blueprint $table) {
            $table->unsignedBigInteger('github_deployment_id')->nullable()->after('error');
            $table->string('github_status', 20)->nullable()->after('github_deployment_id');
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn(['github_deployment_id', 'github_status']);
        });
        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('github_connection_id');
            $table->dropColumn(['github_repo', 'github_hook_id', 'github_key_id', 'github_environment', 'github_linked_at']);
        });
        Schema::dropIfExists('github_connections');
    }
};
