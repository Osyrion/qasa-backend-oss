<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Mail\InvoiceEmail;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Support\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Work that leaves the request and comes back on a worker.
 *
 * The rest of the suite runs the sync driver inside a request that is already
 * bound to an account, which is precisely why it could not see this: a real
 * worker has no request and no authenticated user, so nothing binds its
 * connection and every tenant row is invisible. A queued mailable carrying an
 * invoice died on ModelNotFoundException for an invoice sitting right there.
 *
 * So these push onto the database queue, unbind the connection the way a
 * worker is, and let the worker run in-process — the same code path
 * `queue:work` takes, minus the second process a transactional test could not
 * see into.
 */
beforeEach(function (): void {
    config(['queue.default' => 'database']);
});

/**
 * @return array{0: Invoice, 1: string} the invoice and the account owning it
 */
function invoiceToMail(): array
{
    $owner = createUser();
    $client = Client::factory()->create(['user_id' => $owner->id]);

    return [
        Invoice::factory()->sent()->create(['user_id' => $owner->id, 'client_id' => $client->id]),
        $owner->accountOwnerId(),
    ];
}

/**
 * Drain the queue the way a worker does: bound to nobody.
 */
function workUnbound(): void
{
    TenantContext::clear();

    test()->artisan('queue:work', [
        '--once' => true,
        '--stop-when-empty' => true,
        '--tries' => 1,
    ])->assertSuccessful();
}

it('delivers a queued mailable carrying a tenant model', function (): void {
    [$invoice, $account] = invoiceToMail();

    Mail::to('client@example.com')->queue(new InvoiceEmail($invoice));

    workUnbound();

    // Restoring the model is the first thing the job does — SerializesModels
    // re-reads it by id — so a failure here never reaches the mailer at all.
    expect(asAccount($account, fn () => DB::table('failed_jobs')->count()))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('runs the job bound to the account that dispatched it, not to the worker', function (): void {
    [, $account] = invoiceToMail();

    // A job dispatched from a bound context carries that account in its
    // payload, and this is the worker reading it back in. Recorded through
    // the cache because the closure is serialised — its own variables do not
    // come back.
    dispatch(function (): void {
        Cache::put('tenant_seen_by_job', TenantContext::current());
    });

    workUnbound();

    expect(Cache::get('tenant_seen_by_job'))->toBe($account);
});

it('leaves the worker unbound between jobs', function (): void {
    invoiceToMail();

    dispatch(function (): void {});

    workUnbound();

    // Whatever a job bound itself to must not still be there for the next
    // one. The worker's connection outlives every job on it.
    expect(TenantContext::current())->toBeNull();
});
