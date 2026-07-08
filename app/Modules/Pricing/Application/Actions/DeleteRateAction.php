<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application\Actions;

use App\Modules\Pricing\Domain\Models\Rate;
use App\Modules\Shared\Exceptions\DomainException;

class DeleteRateAction
{
    /**
     * History is append-only — only rates effective from today or later
     * may be deleted (fixing a typo before older work gets priced by it).
     *
     * @throws DomainException
     */
    public function execute(Rate $rate): void
    {
        if (! $rate->isDeletable()) {
            throw DomainException::because(__('pricing.rate_not_deletable'));
        }

        $rate->delete();
    }
}
