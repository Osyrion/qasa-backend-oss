<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Application\Actions;

use App\Modules\Calendar\Application\Contracts\EventRepositoryInterface;
use Carbon\CarbonImmutable;

final readonly class PurgePastEventsAction
{
    public function __construct(
        private EventRepositoryInterface $repository,
    ) {}

    public function execute(CarbonImmutable $today): int
    {
        return $this->repository->purgeEndingBefore($this->cutoff($today));
    }

    private function cutoff(CarbonImmutable $today): CarbonImmutable
    {
        if ((string) config('calendar.retention.mode') === 'months_after_end') {
            $months = (int) config('calendar.retention.months_after_end');

            return $today->subMonths($months)->startOfDay();
        }

        return $today->startOfMonth();
    }
}
