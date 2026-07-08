<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application\Services;

use App\Modules\Pricing\Application\DTOs\RateResolution;
use App\Modules\Pricing\Domain\Enums\RateLevel;
use App\Modules\Pricing\Domain\Models\Rate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Preloaded, in-memory rate history for one (user, client?, order?) scope.
 * Allows pricing many time entries with a single DB query.
 */
final class RateSheet
{
    /** @var array<string, Collection<int, Rate>> rows per level, sorted by valid_from desc */
    private array $byLevel;

    /**
     * @param  Collection<int, Rate>  $rates
     */
    public function __construct(Collection $rates)
    {
        $this->byLevel = $rates
            ->sortByDesc(fn (Rate $rate): string => $rate->valid_from->toDateString())
            ->groupBy(fn (Rate $rate): string => $rate->level->value)
            ->all();
    }

    /**
     * Resolve the rate effective on the given work date.
     * The most specific level wins; within a level the newest
     * valid_from <= $date applies. Future rates are ignored.
     */
    public function rateOn(CarbonInterface $date): ?RateResolution
    {
        foreach ([RateLevel::Order, RateLevel::Client, RateLevel::User] as $level) {
            /** @var Rate|null $match */
            $match = ($this->byLevel[$level->value] ?? collect())
                ->first(fn (Rate $rate): bool => $rate->valid_from->lessThanOrEqualTo($date));

            if ($match !== null) {
                // Tombstone: the level stopped applying at valid_from —
                // fall through to the broader level.
                if ($match->rate === null) {
                    continue;
                }

                return new RateResolution(
                    rate: (float) $match->rate,
                    currency: $match->currency,
                    level: $match->level,
                    validFrom: CarbonImmutable::parse($match->valid_from),
                );
            }
        }

        return null;
    }
}
