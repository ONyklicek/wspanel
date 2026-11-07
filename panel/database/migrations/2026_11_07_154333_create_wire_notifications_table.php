<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = (string) config('wire-core.notifications.database.table', 'wire_notifications');

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $table) {
            // Laravel's own notifications schema, on purpose: an application that
            // already has that table can point the config at it and read both
            // through its own Notifiable::notifications() relation. A shape of
            // our own would have made the two mutually exclusive.
            //
            // `ulid()`, not Laravel's `uuid()`, because the driver writes a ULID
            // — it sorts by the time it was made, which is what makes "newest
            // first" survive a bulk job putting five rows in one second. The two
            // are interchangeable only where a UUID column is a string: SQLite
            // does not check, and MySQL's `uuid()` is a char(36) that swallows
            // anything. Postgres and MariaDB have a real UUID type and reject a
            // 26-character ULID outright — `invalid input syntax for type uuid`
            // on every insert, which is what the database matrix caught.
            //
            // The same edge is why an application pointing this config at
            // Laravel's own `notifications` table wants that table's id to be a
            // string as well.
            $table->ulid('id')->primary();
            $table->string('type');

            // String, not bigint: the recipient may key on a UUID/ULID. No FK —
            // deleting a user should not be blocked by their unread toasts, and
            // a constrained cascade is the application's call, not ours.
            $table->string('notifiable_type');
            $table->string('notifiable_id');

            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // The center's only two queries: this recipient's newest, and their
            // unread count. One composite index serves both.
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('wire-core.notifications.database.table', 'wire_notifications'));
    }
};
