<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Contracts;

/**
 * Writing the admin-edited replacement for a year's income-tax parameters —
 * the rows {@see EffectiveRateTables} then prefers over the shipped
 * SkRates{year}/CzRates{year} classes.
 *
 * The parameter set crosses as a name→value map rather than as a value object
 * per country, because the names *are* the tax law: `flat_expense_cap`,
 * `high_rate_threshold`, `child_bonus_cap_shares` are what the legislation
 * calls them, they are what the admin editor validates, and they are already
 * published field-for-field in its OpenAPI schema. A key outside the set is
 * dropped rather than stored, so this cannot become a way to write a column
 * the editor was never meant to touch.
 *
 * A write is a full replacement for the year, never a merge — the same rule
 * the read side states.
 */
interface RateTableOverrides
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function putSk(int $year, array $parameters): void;

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function putCz(int $year, array $parameters): void;
}
