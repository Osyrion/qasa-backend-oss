<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Models\AiCredential;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('moves an existing encrypted users.anthropic_api_key into ai_credentials, still decryptable', function (): void {
    // Re-create the column the pre-refactor schema had, exactly as it was
    // dropped by 2026_07_22_000005 — the data migration reads it raw via
    // DB::table(), independent of whatever the User model casts today.
    //
    // Two things force the shape of this. Schema changes belong to the owner,
    // not the unprivileged role requests run as, so it goes through
    // pgsql_system. And ALTER TABLE needs a lock no other open transaction
    // may hold, so it has to happen before the wrapping test transaction
    // touches users — hence before createUser() rather than after.
    //
    // The column is left behind on purpose: dropping it would block on that
    // same transaction. It is nullable, no model knows about it, and the next
    // migrate:fresh takes it with the rest of the schema.
    Schema::connection('pgsql_system')->table('users', function (Blueprint $table): void {
        $table->text('anthropic_api_key')->nullable();
    });

    $user = createUser();

    DB::table('users')->where('id', $user->id)->update([
        'anthropic_api_key' => Crypt::encryptString('sk-ant-migrated'),
    ]);

    (require base_path('database/migrations/2026_07_22_000004_migrate_anthropic_api_key_to_ai_credentials.php'))->up();

    $credential = AiCredential::query()->where('user_id', $user->id)->where('provider', 'anthropic')->first();

    expect($credential)->not->toBeNull()
        ->and($credential?->api_key)->toBe('sk-ant-migrated');
});
