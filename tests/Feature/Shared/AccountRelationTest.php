<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;

/**
 * Every tenant-owned model reaches its account through HasUserScope::user().
 *
 * That relation used to be copy-pasted into each model as
 * `belongsTo(User::class)`, naming the *core* class — so in an edition that
 * binds its own User subclass, `$invoice->user` handed back a core instance.
 * Silent, because the core model answers every call: `hasFeature()` returns
 * true unconditionally there, which is exactly right for an edition with no
 * plans and exactly wrong for one with them. Every plan gate reached through a
 * relation rather than through `auth()->user()` was therefore open — the
 * recurring-invoice generator's multi-currency check among them.
 */
it('resolves the account relation to the edition\'s user model', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->create(['user_id' => $user->id, 'client_id' => $client->id]);

    expect($client->user)->toBeInstanceOf(userModel());
    expect($invoice->user)->toBeInstanceOf(userModel());
    expect($invoice->user?->accountOwnerId())->toBe($user->id);
});
