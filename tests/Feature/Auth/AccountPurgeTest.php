<?php

declare(strict_types=1);

use App\Modules\Auth\Application\Actions\PurgeDeletedAccountsAction;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Actions\IssueInvoiceAction;
use App\Modules\Invoicing\Application\Services\InvoicePdfService;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Domain\Models\ActivityLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 1 of docs/plans/GDPR_COMPLIANCE_PLAN.md — the second half of account
 * deletion. DeleteAccountTest covers the first half (soft delete + token
 * revocation); this covers what happens once the grace period runs out.
 */

/**
 * Soft-deletes the account and back-dates deleted_at, since the grace period
 * is counted from it. The update runs bound to the account — users carries an
 * RLS policy like every other table.
 */
function deleteAccountDaysAgo(User $user, int $days): void
{
    asAccount($user, function () use ($user, $days): void {
        $user->delete();

        User::withTrashed()->whereKey($user->id)->update([
            'deleted_at' => now()->subDays($days),
        ]);
    });
}

function purgeAccounts(): int
{
    return (int) app(PurgeDeletedAccountsAction::class)
        ->execute(CarbonImmutable::now());
}

it('leaves an account inside the grace period untouched', function (): void {
    $user = createUser(['name' => 'Jana', 'surname' => 'Nováková']);
    $email = $user->email;

    deleteAccountDaysAgo($user, 29);

    expect(purgeAccounts())->toBe(0);

    $fresh = User::withTrashed()->findOrFail($user->id);

    expect($fresh->name)->toBe('Jana')
        ->and($fresh->email)->toBe($email);
});

it('anonymises an account past the grace period', function (): void {
    $user = createUser([
        'name' => 'Jana',
        'surname' => 'Nováková',
        'phone' => '+421900000000',
        'title' => 'Ing.',
        'address' => 'Hlavná 1',
        'city' => 'Bratislava',
        'postal_code' => '81101',
        'ico' => '12345678',
        'dic' => '1020304050',
        'vat_id' => 'SK1020304050',
        'website' => 'https://example.test',
        'company_name' => 'Nováková s.r.o.',
        'invoice_footer_text' => 'Ďakujeme.',
        'google_id' => 'google-123',
        'clockify_api_key' => 'clockify-secret',
        'clockify_workspace_id' => 'ws-1',
        'color' => '#3B82F6',
    ]);
    $email = $user->email;

    deleteAccountDaysAgo($user, 31);

    expect(purgeAccounts())->toBe(1);

    $fresh = User::withTrashed()->findOrFail($user->id);

    expect($fresh->name)->toBe(User::ANONYMISED)
        ->and($fresh->surname)->toBe('')
        ->and($fresh->email)->toBe("deleted-{$user->id}@invalid")
        ->and($fresh->phone)->toBeNull()
        ->and($fresh->title)->toBeNull()
        ->and($fresh->address)->toBeNull()
        ->and($fresh->city)->toBeNull()
        ->and($fresh->postal_code)->toBeNull()
        ->and($fresh->ico)->toBeNull()
        ->and($fresh->dic)->toBeNull()
        ->and($fresh->vat_id)->toBeNull()
        ->and($fresh->website)->toBeNull()
        ->and($fresh->company_name)->toBeNull()
        ->and($fresh->invoice_footer_text)->toBeNull()
        ->and($fresh->google_id)->toBeNull()
        ->and($fresh->color)->toBeNull()
        ->and($fresh->password)->toBeNull()
        ->and($fresh->clockify_api_key)->toBeNull()
        ->and($fresh->clockify_workspace_id)->toBeNull()
        ->and($fresh->trashed())->toBeTrue();

    // The old address is gone from the table entirely, not just off the
    // visible row — which is what makes it available again.
    expect(User::withTrashed()->where('email', $email)->exists())->toBeFalse();
});

it('clears the two-factor secret and recovery codes', function (): void {
    $user = createUser([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['code-1', 'code-2'],
        'two_factor_confirmed_at' => now(),
    ]);

    deleteAccountDaysAgo($user, 31);
    purgeAccounts();

    $fresh = User::withTrashed()->findOrFail($user->id);

    expect($fresh->two_factor_secret)->toBeNull()
        ->and($fresh->two_factor_recovery_codes)->toBeNull()
        ->and($fresh->two_factor_confirmed_at)->toBeNull();
});

it('keeps issued invoices and their frozen snapshots', function (): void {
    $user = createUser([
        'country' => 'SK',
        'company_name' => 'Nováková s.r.o.',
        'ico' => '12345678',
        'vat_status' => 'payer',
    ]);

    [$invoice, $before] = asAccount($user, function () use ($user): array {
        $client = Client::factory()->create([
            'user_id' => $user->id,
            'client_type' => 'company',
            'company_name' => 'Odberateľ s.r.o.',
            'ico' => '87654321',
            'vat_id' => 'SK2020202020',
        ]);

        $invoice = Invoice::factory()->draft()->create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'invoice_number' => null,
        ]);

        $issued = app(IssueInvoiceAction::class)->execute($invoice);

        // The stored bytes, not the model's array — jsonb reorders keys on
        // write, so comparing decoded arrays would compare something the
        // database never promised to preserve. This is the literal column
        // value before and after.
        return [$issued, DB::table('invoices')->where('id', $issued->id)->sole()];
    });

    expect(json_decode((string) $before->client_snapshot, true))
        ->toHaveKey('name', 'Odberateľ s.r.o.');

    deleteAccountDaysAgo($user, 31);
    purgeAccounts();

    $after = asAccount($user, fn (): object => DB::table('invoices')->where('id', $invoice->id)->sole());

    expect($after->client_snapshot)->toBe($before->client_snapshot)
        ->and($after->supplier_snapshot)->toBe($before->supplier_snapshot);
});

it('still renders the PDF of an issued invoice after the purge', function (): void {
    Storage::fake('public');

    $user = createUser(['country' => 'SK', 'company_name' => 'Nováková s.r.o.']);

    Storage::disk('public')->put("logos/{$user->id}/logo.png", 'not-really-a-png');
    asAccount($user, fn () => $user->forceFill(['logo_path' => "logos/{$user->id}/logo.png"])->save());

    $invoice = asAccount($user, function () use ($user): Invoice {
        $client = Client::factory()->create(['user_id' => $user->id]);

        $invoice = Invoice::factory()->draft()->create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'invoice_number' => null,
        ]);

        $invoice->items()->create([
            'description' => 'Vývoj aplikácie',
            'quantity' => 1,
            'unit' => 'hod',
            'unit_price' => 100,
            'vat_rate' => 0,
            'vat_amount' => 0,
            'total_excl_vat' => 100,
            'total_incl_vat' => 100,
            'sort_order' => 0,
        ]);

        return app(IssueInvoiceAction::class)->execute($invoice->refresh());
    });

    deleteAccountDaysAgo($user, 31);
    purgeAccounts();

    // The logo file is gone, so the render must survive its absence — that is
    // the whole risk of deleting it, and the reason this assertion exists.
    expect(Storage::disk('public')->exists("logos/{$user->id}/logo.png"))->toBeFalse();

    $html = asAccount($user, function () use ($invoice): string {
        $service = app(InvoicePdfService::class);

        return view('invoices::pdf', ['vm' => $service->viewModel(
            $invoice->loadMissing(['client', 'items', 'user', 'bankAccount', 'relatedInvoice', 'workReportLines']),
        )])->render();
    });

    expect($html)->toContain('Nováková s.r.o.')
        ->and($html)->toContain('Vývoj aplikácie');
});

it('deletes the uploaded logo but never touches a remote avatar url', function (): void {
    Storage::fake('public');

    $user = createUser();

    Storage::disk('public')->put("logos/{$user->id}/logo.png", 'not-really-a-png');

    asAccount($user, fn () => $user->forceFill([
        'logo_path' => "logos/{$user->id}/logo.png",
        // Google is the only source of avatar_path and it hands back a URL,
        // not a stored file — nothing to delete, only a column to clear.
        'avatar_path' => 'https://lh3.googleusercontent.com/a/abc123',
    ])->save());

    deleteAccountDaysAgo($user, 31);
    purgeAccounts();

    $fresh = User::withTrashed()->findOrFail($user->id);

    expect($fresh->logo_path)->toBeNull()
        ->and($fresh->avatar_path)->toBeNull()
        ->and(Storage::disk('public')->exists("logos/{$user->id}/logo.png"))->toBeFalse()
        ->and(Storage::disk('public')->directoryExists("logos/{$user->id}"))->toBeFalse();
});

it('deletes any tokens and sessions left behind', function (): void {
    $user = createUser();

    $user->createToken('device');

    DB::table('sessions')->insert([
        'id' => 'session-id-1',
        'user_id' => $user->id,
        'ip_address' => '203.0.113.10',
        'user_agent' => 'Mozilla/5.0',
        'payload' => 'x',
        'last_activity' => time(),
    ]);

    deleteAccountDaysAgo($user, 31);
    purgeAccounts();

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(asAccount($user, fn (): int => DB::table('personal_access_tokens')
            ->where('tokenable_id', $user->id)->count()))->toBe(0);
});

it('records the purge in the activity log', function (): void {
    $user = createUser();

    deleteAccountDaysAgo($user, 31);
    purgeAccounts();

    $entry = asAccount($user, fn (): ?ActivityLog => ActivityLog::withoutGlobalScope('user')
        ->where('user_id', $user->id)
        ->where('event', 'account.purged')
        ->first());

    expect($entry)->not->toBeNull()
        ->and($entry?->actor_id)->toBeNull();
});

it('anonymises every eligible account in one run', function (): void {
    $first = createUser(['name' => 'Prvý']);
    $second = createUser(['name' => 'Druhý']);
    $untouched = createUser(['name' => 'Tretí']);

    deleteAccountDaysAgo($first, 31);
    deleteAccountDaysAgo($second, 45);

    expect(purgeAccounts())->toBe(2);

    // Each read is bound to its own account — users carries an RLS policy,
    // so a cross-account read from whatever happens to be bound finds nothing.
    $name = fn (User $user): ?string => asAccount(
        $user,
        fn (): string => User::withTrashed()->findOrFail($user->id)->name,
    );

    expect($name($first))->toBe(User::ANONYMISED)
        ->and($name($second))->toBe(User::ANONYMISED)
        ->and($name($untouched))->toBe('Tretí');
});

it('is idempotent', function (): void {
    $user = createUser();

    deleteAccountDaysAgo($user, 31);

    expect(purgeAccounts())->toBe(1)
        ->and(purgeAccounts())->toBe(0);
});

it('reports what it would do without writing anything in dry-run', function (): void {
    $user = createUser(['name' => 'Jana']);

    deleteAccountDaysAgo($user, 31);

    $this->artisan('qasa:accounts:purge', ['--dry-run' => true])
        ->expectsOutputToContain('1')
        ->assertSuccessful();

    expect(User::withTrashed()->findOrFail($user->id)->name)->toBe('Jana');
});
