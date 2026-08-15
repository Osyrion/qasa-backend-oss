<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Actions\PurgeRequestMetadataAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 of docs/plans/GDPR_COMPLIANCE_PLAN.md — an IP address is personal
 * data, and until now three tables held one indefinitely.
 *
 * The rule that shapes this: a *token* is an access credential and outlives
 * its metadata, so it is never deleted for the sake of retention — only the
 * ip_address/user_agent columns are cleared. A *session* row is nothing but
 * that metadata once it has expired, so it goes entirely.
 */
function purgeRequestMetadata(): int
{
    return app(PurgeRequestMetadataAction::class)->execute(CarbonImmutable::now());
}

it('deletes sessions outside the retention window', function (): void {
    $user = createUser();

    DB::table('sessions')->insert([
        [
            'id' => 'stale-session',
            'user_id' => $user->id,
            'ip_address' => '203.0.113.10',
            'user_agent' => 'Mozilla/5.0',
            'payload' => 'x',
            'last_activity' => now()->subDays(91)->timestamp,
        ],
        [
            'id' => 'live-session',
            'user_id' => $user->id,
            'ip_address' => '203.0.113.11',
            'user_agent' => 'Mozilla/5.0',
            'payload' => 'x',
            'last_activity' => now()->subDays(2)->timestamp,
        ],
    ]);

    purgeRequestMetadata();

    expect(DB::table('sessions')->where('id', 'stale-session')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'live-session')->exists())->toBeTrue();
});

it('clears token metadata without revoking the token', function (): void {
    $user = createUser();

    $token = $user->createToken('old-device');

    asAccount($user, fn () => DB::table('personal_access_tokens')
        ->where('id', $token->accessToken->id)
        ->update([
            'ip_address' => '203.0.113.10',
            'user_agent' => 'Mozilla/5.0',
            'last_used_at' => now()->subDays(91),
        ]));

    purgeRequestMetadata();

    $row = asAccount($user, fn (): object => DB::table('personal_access_tokens')
        ->where('id', $token->accessToken->id)->sole());

    expect($row->ip_address)->toBeNull()
        ->and($row->user_agent)->toBeNull();

    // The whole point of clearing rather than deleting: the credential still
    // works. A retention rule must not log an integration out.
    $this->withToken($token->plainTextToken)->getJson('/api/v1/auth/me')->assertOk();
});

it('leaves recently used token metadata alone', function (): void {
    $user = createUser();

    $token = $user->createToken('current-device');

    asAccount($user, fn () => DB::table('personal_access_tokens')
        ->where('id', $token->accessToken->id)
        ->update([
            'ip_address' => '203.0.113.10',
            'user_agent' => 'Mozilla/5.0',
            'last_used_at' => now()->subDays(3),
        ]));

    purgeRequestMetadata();

    $row = asAccount($user, fn (): object => DB::table('personal_access_tokens')
        ->where('id', $token->accessToken->id)->sole());

    expect($row->ip_address)->toBe('203.0.113.10')
        ->and($row->user_agent)->toBe('Mozilla/5.0');
});

it('falls back to created_at for a token that was never used', function (): void {
    $user = createUser();

    $token = $user->createToken('never-used');

    asAccount($user, fn () => DB::table('personal_access_tokens')
        ->where('id', $token->accessToken->id)
        ->update([
            'ip_address' => '203.0.113.10',
            'last_used_at' => null,
            'created_at' => now()->subDays(91),
        ]));

    purgeRequestMetadata();

    $row = asAccount($user, fn (): object => DB::table('personal_access_tokens')
        ->where('id', $token->accessToken->id)->sole());

    expect($row->ip_address)->toBeNull();
});

it('reaches tokens across every account', function (): void {
    $first = createUser();
    $second = createUser();

    $tokens = [];

    foreach ([$first, $second] as $user) {
        // Bound per account for the write too: creating the second user left
        // the connection on that account, and personal_access_tokens rejects
        // an insert for anyone else outright.
        $tokens[$user->id] = asAccount($user, function () use ($user): int {
            $token = $user->createToken('device');

            DB::table('personal_access_tokens')
                ->where('id', $token->accessToken->id)
                ->update(['ip_address' => '203.0.113.10', 'last_used_at' => now()->subDays(91)]);

            return $token->accessToken->id;
        });
    }

    // personal_access_tokens carries an RLS policy, so a single statement
    // from an unbound command would match nothing at all — this is the
    // assertion that the action walks the accounts instead.
    expect(purgeRequestMetadata())->toBeGreaterThanOrEqual(2);

    foreach ([$first, $second] as $user) {
        $row = asAccount($user, fn (): object => DB::table('personal_access_tokens')
            ->where('id', $tokens[$user->id])->sole());

        expect($row->ip_address)->toBeNull();
    }
});

it('runs from the console', function (): void {
    $this->artisan('qasa:privacy:purge-request-metadata')->assertSuccessful();
});
