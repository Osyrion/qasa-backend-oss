<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Rates;

use App\Modules\Taxation\Application\Contracts\EffectiveRateTables;
use App\Modules\Taxation\Domain\Contracts\CzRateTable;
use App\Modules\Taxation\Domain\Contracts\SkRateTable;
use App\Modules\Taxation\Infrastructure\Cz\Rates\CzRates2025;
use App\Modules\Taxation\Infrastructure\Cz\Rates\CzRates2026;
use App\Modules\Taxation\Infrastructure\Cz\Rates\DbBackedCzRateTable;
use App\Modules\Taxation\Infrastructure\Sk\Rates\DbBackedSkRateTable;
use App\Modules\Taxation\Infrastructure\Sk\Rates\SkRates2025;
use App\Modules\Taxation\Infrastructure\Sk\Rates\SkRates2026;

/**
 * The one place that knows which rate table a year resolves to.
 *
 * Lives in Infrastructure because that is where both sources are: the
 * admin-edited rows in the database and the figures shipped in code.
 */
final class ConfiguredRateTables implements EffectiveRateTables
{
    public function sk(int $year): ?SkRateTable
    {
        return DbBackedSkRateTable::forYear($year) ?? match ($year) {
            2025 => new SkRates2025,
            2026 => new SkRates2026,
            default => null,
        };
    }

    public function cz(int $year): ?CzRateTable
    {
        return DbBackedCzRateTable::forYear($year) ?? match ($year) {
            2025 => new CzRates2025,
            2026 => new CzRates2026,
            default => null,
        };
    }
}
