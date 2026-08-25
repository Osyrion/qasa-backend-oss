<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Contracts;

use App\Modules\Taxation\Domain\Contracts\CzRateTable;
use App\Modules\Taxation\Domain\Contracts\SkRateTable;

/**
 * The income-tax rate table actually in effect for a year.
 *
 * An admin-edited row is a full replacement for the year, checked before the
 * hardcoded SkRates{year}/CzRates{year} class — never merged with it. That
 * fallback used to be written twice, in the calculators and again in Admin's
 * parameter editor, which meant the editor could show one set of figures while
 * a return was computed from another. One place decides now.
 *
 * Null means the year is not covered at all: no admin row and no shipped
 * table. The calculator turns that into a domain error, the editor into a 404.
 */
interface EffectiveRateTables
{
    public function sk(int $year): ?SkRateTable;

    public function cz(int $year): ?CzRateTable;
}
