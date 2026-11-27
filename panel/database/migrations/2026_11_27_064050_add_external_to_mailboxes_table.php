<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A mailbox's provider (external mailbox): where its mail is downloaded
     * from and the SMTP it sends through. The passwords stay on the server.
     */
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->json('external')->nullable()->after('forward');
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn('external');
        });
    }
};
