<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * Every money column in this schema is decimal(_, 2), so a validation rule
 * that only says `numeric` lets Postgres do the rounding: 0.001 was accepted
 * as a payment and stored as 0.00, and 10.005 as a payment of 10.01 nobody
 * made. `decimal:0,2` is what makes the boundary agree with the column.
 *
 * The rule is enforced here rather than left to review because the concept
 * lives in nine places across six modules — it was already inconsistent
 * once, and one new DTO is all it takes to reopen the hole.
 *
 * Scope is fields named `amount` or `*_amount`. Unit prices are deliberately
 * out: `unit_price` sits on a decimal(10,2) column too and rounds the same
 * way, but sub-cent unit prices are a plausible thing to want (0.004 × 1000
 * units), so tightening it is a product decision about widening the column,
 * not a validation bug. See docs/app/APLIKACIA.md chapter 18.
 */
it('validates every money amount against the precision its column keeps', function (): void {
    $offenders = [];
    $root = dirname(__DIR__, 2);

    foreach ((new Finder)->files()->in($root.'/app/Modules')->name('*.php') as $file) {
        $contents = $file->getContents();

        preg_match_all(
            "/'((?:[a-z_]+_)?amount(?:_[a-z_]+)?)'\s*=>\s*\[([^\]]*)\]/",
            $contents,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as [, $field, $rules]) {
            if (! str_contains($rules, "'numeric'") || str_contains($rules, "'decimal:")) {
                continue;
            }

            $offenders[] = str_replace($root.'/', '', (string) $file->getRealPath()).": {$field}";
        }
    }

    expect($offenders)->toBe([], "money amounts validated without a decimal: rule:\n".implode("\n", $offenders));
});
