<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs \NyonCode\WireCore\Foundation\Preferences\Drivers\DatabasePreferenceDriver:
 * one row per (user_id, surface_key, view) holding a JSON bag of whatever a
 * surface remembers for a user — a table's hidden columns and row-expansion
 * baseline, a dashboard's widget layout. `view` is the saved view's name, and an
 * empty string is the unnamed current layout. Publish with
 *   php artisan vendor:publish --tag="wire-core::migrations"
 * only if a surface's configured driver is `database`.
 *
 * ## It renames rather than creates, where there is something to rename
 *
 * This store shipped in `wire-table` as `table_preferences`, keyed by
 * `table_key`. It came down to `wire-core` when a dashboard needed the same
 * thing, and the name had to follow: a table called `table_preferences` holding
 * dashboard layouts is a name that lies to the next person reading the schema.
 *
 * So an installation that already has the old table gets its rows renamed into
 * place — nobody loses a saved layout over a refactor they did not ask for —
 * and a fresh installation gets the table created. Both paths end at the same
 * schema, which is what makes the `down()` honest in either direction.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wire_preferences')) {
            return;
        }

        if (Schema::hasTable('table_preferences')) {
            Schema::rename('table_preferences', 'wire_preferences');
            Schema::table('wire_preferences', function (Blueprint $table) {
                $table->renameColumn('table_key', 'surface_key');
            });

            return;
        }

        Schema::create('wire_preferences', function (Blueprint $table) {
            $table->id();
            // No FK constraint: the app's user key type/table is unknown, and the
            // driver also stores a null user_id for shared/guest rows.
            $table->string('user_id')->nullable()->index();
            // A table key, a dashboard key — whatever the surface calls itself.
            $table->string('surface_key');
            // The saved view's name. Empty string, never NULL, for the unnamed
            // current layout: MySQL and SQLite treat two NULLs as distinct in a
            // unique index, so a nullable column here would let a second row for
            // the same triple through and the layout would silently fork.
            $table->string('view')->default('');
            $table->json('preferences');
            $table->timestamps();

            $table->unique(['user_id', 'surface_key', 'view']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wire_preferences');
    }
};
