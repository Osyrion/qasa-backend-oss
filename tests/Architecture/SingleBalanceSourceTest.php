<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * "How much does this invoice still owe" is `Invoice::balance()` and nothing
 * else. It is the figure the Stripe webhook checks a captured payment
 * against, the PayableAmount in the UBL and ISDOC exports, what a reminder
 * e-mail quotes at the client, and what decides whether recording a payment
 * flips the invoice to paid — so a second opinion on it is not a style
 * question.
 *
 * Two services had grown their own copy: PaymentMatchingService (which
 * decides *which* invoice an incoming bank payment is offered against) and
 * FinanceOverviewService (the outstanding figure on the dashboard). Both
 * were byte-identical to each other and justified in a comment — the first
 * claimed calling Invoice::balance() would cost an N+1 sum query, the second
 * simply cited the first. Neither was true: balance() reads the same
 * withSum('payments', 'amount') aggregate when it is present, and both
 * services eager-load exactly that.
 *
 * The copies also failed differently from the original. Given an invoice
 * *without* the aggregate loaded, `$invoice->payments_sum_amount ?? 0`
 * silently reports nothing as paid, while balance() falls back to summing
 * the relation. No caller reached that branch, which is why this was a
 * latent divergence rather than a live bug — but the bank matcher answering
 * "the full amount is outstanding" for a half-paid invoice is not a failure
 * mode worth keeping one query away.
 */
it('computes an invoice balance in exactly one place', function (): void {
    $modules = dirname(__DIR__, 2).'/app/Modules';

    // The subtraction itself — `->total - … payments_sum…`, in either order,
    // however spaced. Selecting or ordering by both columns in one query is
    // not a second implementation, so the `-` has to be there.
    $pattern = '/(->total\s*-\s*.{0,60}?payments_sum|payments_sum\w*\s*-\s*.{0,60}?->total)/s';

    $finder = (new Finder)->files()->in($modules)->name('*.php');

    $violations = [];

    foreach ($finder as $file) {
        $relativePath = str_replace($modules.'/', '', (string) $file->getRealPath());

        // The one place that may: the model the figure belongs to.
        if ($relativePath === 'Invoicing/Domain/Models/Invoice.php') {
            continue;
        }

        if (preg_match($pattern, $file->getContents()) === 1) {
            $violations[] = $relativePath;
        }
    }

    expect($violations)->toBe(
        [],
        'These files compute an invoice balance themselves instead of calling Invoice::balance(): '
        .implode(', ', $violations),
    );
});
