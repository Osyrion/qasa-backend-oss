<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Actions\RemindInvoiceAction;
use App\Modules\Invoicing\Application\Actions\SendInvoiceEmailAction;
use App\Modules\Invoicing\Application\Mail\InvoiceEmail;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\Mail;

/*
 * `verified` used to guard the Taxation module and nothing else, so an
 * account registered with an address nobody had proved could still have this
 * deployment e-mail invoices, payment reminders, quotes and team invitations
 * on its behalf — with the account's own branding on them. Filing a tax
 * return was gated; mailing strangers was not.
 *
 * The line drawn here is "reaches a third party": sending mail, and minting
 * the public/portal links a third party is meant to open. Everything the
 * account does to itself — creating clients, drafting invoices, importing —
 * stays open, so this is not an onboarding wall.
 *
 * Route middleware alone would not have been enough, which is the point of
 * the second half of this file: the same send actions run from the scheduler
 * and from an automation listener, where no request exists.
 */
/**
 * Subscribed on purpose: without a paid plan the quote routes answer 403 from
 * the `feature:quotes` gate, and two rows of the dataset below would then pass
 * whether or not verification is enforced at all. (No-op in the OSS edition,
 * which has no plans — see tests/Pest.oss.php.)
 */
function unverifiedOwner(): User
{
    $owner = createUser(['email_verified_at' => null]);

    subscribeToPaidPlan($owner);

    return $owner;
}

function sendableInvoice(User $owner): Invoice
{
    $client = Client::factory()->for($owner)->create(['email' => 'client@example.test']);

    return Invoice::factory()->for($owner)->create([
        'client_id' => $client->id,
        'status' => 'sent',
    ]);
}

beforeEach(function (): void {
    Mail::fake();
});

it('refuses to mail a third party for an unverified account', function (string $method, string $path): void {
    $owner = unverifiedOwner();
    $invoice = sendableInvoice($owner);
    $quote = Quote::factory()->for($owner)->create([
        'client_id' => $invoice->client_id,
    ]);

    $path = str_replace(['{invoice}', '{quote}', '{client}'], [$invoice->id, $quote->id, $invoice->client_id], $path);

    $this->actingAs($owner)->json($method, $path)->assertForbidden();

    Mail::assertNothingQueued();
})->with([
    'invoice e-mail' => ['POST', '/api/v1/invoices/{invoice}/email'],
    'invoice reminder' => ['POST', '/api/v1/invoices/{invoice}/remind'],
    'quote e-mail' => ['POST', '/api/v1/quotes/{quote}/email'],
    'invoice public link' => ['POST', '/api/v1/invoices/{invoice}/public-link'],
    'quote public link' => ['POST', '/api/v1/quotes/{quote}/public-link'],
    'client portal link' => ['POST', '/api/v1/clients/{client}/portal-link'],
]);

it('lets a verified account mail the same document', function (): void {
    $owner = createUser();
    $invoice = sendableInvoice($owner);

    $this->actingAs($owner)
        ->postJson("/api/v1/invoices/{$invoice->id}/email")
        ->assertOk();

    Mail::assertQueued(InvoiceEmail::class);
});

it('leaves the account free to work on its own data while unverified', function (): void {
    $owner = unverifiedOwner();

    $this->actingAs($owner)->getJson('/api/v1/invoices')->assertOk();
    $this->actingAs($owner)->getJson('/api/v1/clients')->assertOk();
    $this->actingAs($owner)
        ->postJson('/api/v1/clients', [
            'client_type' => 'individual',
            'name' => 'Ján',
            'surname' => 'Novák',
            'country' => 'SK',
            'currency' => 'EUR',
            'locale' => 'sk',
            'is_vat_payer' => false,
        ])
        ->assertCreated();
});

/*
 * The half route middleware cannot reach. GenerateRecurringInvoicesCommand,
 * AutoRemindInvoicesCommand and AutoSendInvoiceOnIssue all call these same
 * actions with no request in flight, so an unverified account could otherwise
 * set up a recurring template and have the scheduler send for it.
 */
it('refuses to send from a scheduled or listener context too', function (): void {
    $owner = unverifiedOwner();
    $invoice = sendableInvoice($owner);

    expect(fn () => app(SendInvoiceEmailAction::class)->execute($invoice))
        ->toThrow(DomainException::class);

    expect(fn () => app(RemindInvoiceAction::class)->execute($invoice))
        ->toThrow(DomainException::class);

    Mail::assertNothingQueued();
});

it('sends from a scheduled context once the owner has verified', function (): void {
    $owner = createUser();
    $invoice = sendableInvoice($owner);

    app(SendInvoiceEmailAction::class)->execute($invoice);

    Mail::assertQueued(InvoiceEmail::class);
});

it('does not block anyone when the requirement is switched off', function (): void {
    config()->set('qasa.require_verified_sender', false);

    $owner = unverifiedOwner();
    $invoice = sendableInvoice($owner);

    app(SendInvoiceEmailAction::class)->execute($invoice);

    Mail::assertQueued(InvoiceEmail::class);
});
