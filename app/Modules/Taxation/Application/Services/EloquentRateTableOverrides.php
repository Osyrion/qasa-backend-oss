<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Services;

use App\Modules\Taxation\Application\Contracts\RateTableOverrides;
use App\Modules\Taxation\Domain\Models\CzTaxRateParameterSet;
use App\Modules\Taxation\Domain\Models\SkTaxRateParameterSet;

final class EloquentRateTableOverrides implements RateTableOverrides
{
    public function putSk(int $year, array $parameters): void
    {
        SkTaxRateParameterSet::query()->updateOrCreate(
            ['year' => $year],
            $this->accepted($parameters, (new SkTaxRateParameterSet)->getFillable()),
        );
    }

    public function putCz(int $year, array $parameters): void
    {
        CzTaxRateParameterSet::query()->updateOrCreate(
            ['year' => $year],
            $this->accepted($parameters, (new CzTaxRateParameterSet)->getFillable()),
        );
    }

    /**
     * `year` is the key, not a parameter, so a caller cannot move a set to a
     * different year by smuggling it into the payload.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<int, string>  $fillable
     * @return array<string, mixed>
     */
    private function accepted(array $parameters, array $fillable): array
    {
        return array_intersect_key(
            $parameters,
            array_flip(array_values(array_diff($fillable, ['year']))),
        );
    }
}
