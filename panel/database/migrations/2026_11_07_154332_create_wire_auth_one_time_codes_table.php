<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create((string) config('wire-module-auth.codes.table', 'wire_auth_one_time_codes'), function (Blueprint $table): void {
            $table->id();

            // The pair is the address of a code, not two columns that happen to
            // be looked up together: a code mailed to confirm an address must
            // not open the sign-in challenge (ADR 0037 §1). Unique, because
            // issuing replaces — a person who asks twice has one code, and it is
            // the one in the newest mail.
            $table->string('purpose', 32);
            $table->string('identifier');
            $table->unique(['purpose', 'identifier']);

            // Hashed the way Laravel hashes a reset token. A database copy is
            // then a list of dead hashes rather than a set of live credentials.
            $table->string('code');

            // What the flow has to carry from the mail to the form — the
            // address an e-mail verification code was asked for. Plain JSON, so
            // never a secret: the reset flow once kept the broker's token here,
            // which put a credential Laravel stores hashed back in readable form.
            $table->text('payload')->nullable();

            // Wrong guesses, counted on the code itself. The route throttle
            // counts an address and an IP, and neither is the thing being
            // guessed.
            $table->unsignedTinyInteger('attempts')->default(0);

            // dateTime, not timestamp. MariaDB before 10.10 (and MySQL with
            // explicit_defaults_for_timestamp off) gives the first NOT NULL
            // timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP —
            // every wrong guess would move the expiry to "now" — and the second
            // a zero date, which strict mode refuses, so the migration fails.
            // Both values are written by DatabaseOneTimeCodes; no default is wanted.
            $table->dateTime('expires_at');
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('wire-module-auth.codes.table', 'wire_auth_one_time_codes'));
    }
};
