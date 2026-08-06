<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the idempotency row a *claim* rather than a receipt.
 *
 * The middleware used to look the key up, run the handler, and only then
 * insert. Two requests carrying the same Idempotency-Key both missed the
 * lookup, both ran the handler, and only the loser's insert failed — by
 * which point the duplicate invoice or payment it was supposed to prevent
 * already existed. Replay protection that only works when the retry arrives
 * after the original finished is not replay protection; a double-click and a
 * client timeout-and-retry are precisely the concurrent case.
 *
 * The fix is to insert first and let the unique key_hash be the lock, which
 * needs a row that can exist before there is a response to store:
 * response_status becomes nullable, and NULL now means "someone else is
 * running this right now" — answered with 409 rather than a replay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->smallInteger('response_status')->nullable()->change();
        });
    }

    public function down(): void
    {
        // An in-flight claim has no status to keep; drop those rows rather
        // than invent one for them.
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->smallInteger('response_status')->nullable(false)->change();
        });
    }
};
