<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Services\VatRateSeederService;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Support\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Two\User as SocialiteTwoUser;
use Tests\RefreshDatabaseAsOwner;
use Tests\TestCase;

// Edition overlay (SaaS repo only) — forces env before the app boots.
if (file_exists(__DIR__.'/Pest.edition.php')) {
    require __DIR__.'/Pest.edition.php';
} else {
    // Core stand-ins for the helpers the overlay would have provided.
    require __DIR__.'/Pest.oss.php';
}

uses(TestCase::class, RefreshDatabaseAsOwner::class)->in('Feature', 'Unit', 'Invariants');

/**
 * The edition's User model class (auth provider config).
 *
 * @return class-string<User>
 */
function userModel(): string
{
    /** @var class-string<User> $model */
    $model = config('auth.providers.users.model');

    return $model;
}

/**
 * Creates a user of the edition's User model.
 *
 * $configureFactory reaches a factory state (->withTwoFactor(), say) that
 * plain attributes can't — createUser() still owns id generation and
 * binding either way, so a test never has to repeat that part just to add
 * one state call.
 *
 * @param  array<string, mixed>  $attributes
 * @param  (Closure(Factory<User>): Factory<User>)|null  $configureFactory
 */
function createUser(array $attributes = [], ?Closure $configureFactory = null): User
{
    /** @var class-string<User> $model */
    $model = userModel();

    // users is tenant-scoped too (phase 7, docs/plans/POSTGRES_RLS_PLAN.md)
    // — the row about to be created is its own account (unless the caller
    // passed its own 'owner_id', team-member-shaped), so the id has to be
    // bound before the insert, not after. Row Level Security needs the
    // connection bound to an account before anything can be written for it,
    // and test setup runs outside any request, where nothing would bind it.
    // The first user created in a test takes that role — which is the one a
    // test acts as in almost every case. A test that then sets up data for a
    // second account says so with asAccount().
    $attributes = ['id' => (string) (new $model)->newUniqueId(), ...$attributes];
    TenantContext::set($attributes['owner_id'] ?? $attributes['id']);

    /** @var Factory<User> $factory */
    $factory = $model::factory();
    if ($configureFactory !== null) {
        $factory = $configureFactory($factory);
    }

    $user = $factory->create($attributes);
    assert($user instanceof User);

    // Factory path bypasses RegisterUserAction — the SaaS overlay mirrors
    // the Owner-role assignment its listener would do.
    if (function_exists('grantOwnerRole')) {
        grantOwnerRole($user);
    }

    return $user;
}

/**
 * An account owner with nothing switched off.
 *
 * In the SaaS edition that means a paid plan: feature gates and — the one
 * that bites in tests that never mention billing — EnsureApiTokenFeatureAccess,
 * which rejects any request authenticated by a token whose abilities are not
 * literally ['*'] once the plan lacks api_access. Sanctum::actingAs() builds a
 * mock token, so every scoped-token test trips that without a plan. The core
 * edition has no plans and the user is already unrestricted, so this is
 * createUser() there.
 *
 * Same function_exists shape as grantOwnerRole above: the helper it reaches
 * for ships only in tests/Pest.edition.php.
 *
 * @param  array<string, mixed>  $attributes
 */
function createEntitledOwner(array $attributes = []): User
{
    $user = createUser($attributes);

    if (function_exists('subscribeToPaidPlan')) {
        subscribeToPaidPlan($user);
    }

    return $user;
}

/**
 * Runs $work with the connection bound to $user's account.
 *
 * For tests that build data for more than one account: without this, rows for
 * anyone but the first user created are refused by the policy.
 *
 * Takes an account id as well as a user, because the id is often what is to
 * hand — every tenant row's user_id *is* its account, and reaching for
 * `$row->user` to get back to it only risks loading a relation the policy is
 * currently hiding.
 *
 * @param  callable(): mixed  $work
 */
function asAccount(User|string $user, callable $work): mixed
{
    $previous = TenantContext::current();
    TenantContext::set($user instanceof User ? $user->accountOwnerId() : $user);

    try {
        return $work();
    } finally {
        $previous === null ? TenantContext::clear() : TenantContext::set($previous);
    }
}

/**
 * Whether a test guarded on an external tool should be skipped — and, in CI,
 * whether the missing tool should fail the build instead.
 *
 * A skip is invisible. PeppolValidationTest guarded on `which java` alone and
 * spent months reporting green on checkouts that could not run it: the suite
 * said "passed", the summary said "skipped", and nobody reads the summary. The
 * distinction that matters is who is looking. On a laptop without a JRE a skip
 * is the right answer — the tool is genuinely optional there. In CI it never
 * is: every dependency these tests need is installed by the workflow, so a
 * missing one means the pipeline quietly stopped checking something, which is
 * the failure, not a reason to pass.
 *
 * Read from getenv() rather than env(): phpunit.xml's <env> entries cannot
 * override a variable the process already has, and CI is exactly that case.
 *
 * @param  bool  $available  result of the caller's own probe
 * @param  string  $tooling  what is missing, in words
 * @param  string  $install  how CI installs it — the fix, when this fails
 */
function skipUnlessInstalled(bool $available, string $tooling, string $install): bool
{
    if ($available) {
        return false;
    }

    if (filter_var(getenv('CI'), FILTER_VALIDATE_BOOL)) {
        throw new RuntimeException(
            "{$tooling} is missing in CI, so this test would have been skipped and the build would have gone green "
            ."without ever running it. CI installs this with: {$install}"
        );
    }

    return true;
}

/**
 * Builds a concrete Socialite user (Two\User, same class GoogleProvider
 * returns) instead of a bare Contracts\User mock — LoginWithGoogleAction
 * checks `instanceof AbstractUser` to read the email_verified claim off
 * getRaw(), which a mock of the interface alone can't satisfy.
 */
function mockGoogleUser(string $email, ?string $id = null, bool $emailVerified = true, ?string $name = null): SocialiteTwoUser
{
    $user = new SocialiteTwoUser;
    $user->map([
        'id' => $id,
        'email' => $email,
        'avatar' => null,
        'name' => $name,
    ]);
    $user->setRaw(['email_verified' => $emailVerified]);

    return $user;
}

/**
 * Signs a Stripe webhook payload the same way Stripe itself does, so tests
 * can exercise the real /stripe/webhook route including
 * VerifyWebhookSignature (pinned on unconditionally in
 * SubscriptionsServiceProvider — an unsigned request is now rejected
 * outright rather than silently trusted).
 *
 * @param  array<string, mixed>  $payload
 * @return TestResponse<Response>
 */
function postStripeWebhook(object $test, array $payload): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $secret = (string) config('cashier.webhook.secret');
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

    // ->call() doesn't merge withHeader()'s defaultHeaders (only post()/json()
    // do, via transformHeadersToServerVars) — set the CGI server var directly.
    return $test->call('POST', '/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
    ], content: $body);
}

/**
 * Creates a user with seeded VAT rates, ready to own supplier invoices.
 * Shared by the supplier-invoice and invoice-inbox feature tests.
 *
 * @param  array<string, mixed>  $attributes
 */
function createSupplierInvoiceOwner(array $attributes = []): User
{
    $user = createUser(array_merge(['country' => 'SK', 'vat_status' => 'payer'], $attributes));
    app(VatRateSeederService::class)->seedFor($user);

    return $user;
}

/**
 * Creates a vendor client belonging to the given user.
 */
function vendorClientFor(User $user): Client
{
    return Client::factory()->vendor()->create(['user_id' => $user->id]);
}

/**
 * Valid payload for creating a supplier invoice.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function supplierInvoicePayload(string $clientId, array $overrides = []): array
{
    return array_merge([
        'client_id' => $clientId,
        'supplier_invoice_number' => 'INV-'.fake()->unique()->numberBetween(1, 99999),
        'issued_at' => now()->toDateString(),
        'currency' => 'EUR',
        'vat_lines' => [
            ['vat_rate' => 23, 'base' => 100, 'vat_amount' => 23],
        ],
    ], $overrides);
}

/**
 * A user with seeded VAT rates plus a same-country client, for the
 * VAT control statement tests.
 *
 * @return array{0: User, 1: Client}
 */
function vcsScope(string $country, string $vatStatus = 'payer'): array
{
    $user = createUser(['country' => $country, 'vat_status' => $vatStatus]);
    app(VatRateSeederService::class)->seedFor($user);

    // These are VAT-report scenarios, not free-tier scenarios — a paid plan
    // keeps the SaaS free-tier client locking out of the picture. In core the
    // helper is a no-op, so no edition check is needed (and naming the SaaS
    // User here would break the generated core).
    subscribeToPaidPlan($user);

    $client = Client::factory()->create(['user_id' => $user->id, 'country' => $country]);

    return [$user, $client];
}

function vcsIssueInvoice(object $test, User $user, Client $client, string $issuedAt, float $unitPrice, float $vatRate): Invoice
{
    $currency = $client->country === 'CZ' ? 'CZK' : 'EUR';

    $created = $test->actingAs($user)->postJson('/api/v1/invoices', [
        'client_id' => $client->id,
        'issued_at' => $issuedAt,
        'due_at' => Carbon::parse($issuedAt)->addDays(14)->toDateString(),
        'currency' => $currency,
    ])->assertCreated();

    $test->actingAs($user)->postJson("/api/v1/invoices/{$created->json('data.id')}/items", [
        'description' => 'Služby', 'quantity' => 1, 'unit' => 'ks', 'unit_price' => $unitPrice, 'vat_rate' => $vatRate,
    ])->assertCreated();

    $test->actingAs($user)->postJson("/api/v1/invoices/{$created->json('data.id')}/status", ['status' => 'sent'])
        ->assertOk();

    return Invoice::withoutGlobalScope('user')->whereKey($created->json('data.id'))->firstOrFail();
}
