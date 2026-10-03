<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Offset pagination is only well defined under a total order.
 *
 * invoices.issued_at is a `date`, and it is the default sort for the list.
 * Every invoice raised on one day therefore ties — which is not a corner
 * case: GenerateRecurringInvoicesCommand raises a whole batch on one day.
 * Postgres will not invent an order for tied rows, so without a tiebreaker
 * the same invoice can appear on page one and page two while another is
 * never returned at all.
 *
 * Asserted on the statement rather than by paging twice and comparing: at test
 * volumes the planner picks one plan and sticks to it, so a duplicate would
 * only show up once the data is big enough to change its mind — exactly the
 * point at which it is a production incident instead of a test failure. What
 * is deterministic, and what actually has to hold, is that the ORDER BY names
 * a unique column at all.
 */
it('orders the invoice list by a unique column so pages cannot overlap', function (): void {
    $owner = createEntitledOwner();
    $client = Client::factory()->create(['user_id' => $owner->id]);

    $issuedAt = now()->subDays(10)->toDateString();

    for ($i = 0; $i < 3; $i++) {
        Invoice::factory()->create([
            'user_id' => $owner->id,
            'client_id' => $client->id,
            'issued_at' => $issuedAt,
        ]);
    }

    $listing = null;

    DB::listen(function (QueryExecuted $query) use (&$listing): void {
        if ($listing === null
            && str_contains($query->sql, 'from "invoices"')
            && str_contains($query->sql, 'limit')) {
            $listing = $query->sql;
        }
    });

    $this->actingAs($owner)->getJson('/api/v1/invoices?per_page=2')->assertOk();

    expect($listing)->not->toBeNull('the invoice list issued no paginated select');

    // The ORDER BY clause itself, not the whole statement: withSum() puts
    // "invoices"."id" in a correlated subquery, so searching the statement for
    // the key passes with no tiebreaker at all.
    $matched = preg_match('/ order by (.*) limit /', (string) $listing, $orderBy) === 1;

    expect($matched)->toBeTrue('the paginated select has no order by at all')
        ->and($orderBy[1] ?? '')->toContain('"id"');
});

it('keeps the caller\'s sort and only breaks its ties', function (): void {
    $owner = createEntitledOwner();
    $client = Client::factory()->create(['user_id' => $owner->id]);

    foreach (['2026-01-05', '2026-01-05', '2026-01-04'] as $date) {
        Invoice::factory()->create([
            'user_id' => $owner->id,
            'client_id' => $client->id,
            'issued_at' => $date,
        ]);
    }

    $response = $this->actingAs($owner)->getJson('/api/v1/invoices')->assertOk();

    $dates = array_column($response->json('data'), 'issued_at');

    // The requested order still leads; the key only decides within a group.
    expect($dates)->toBe(['2026-01-05', '2026-01-05', '2026-01-04']);
});
