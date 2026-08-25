<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Services;

use App\Modules\Taxation\Application\Contracts\TaxFilingArchive;
use App\Modules\Taxation\Domain\Enums\TaxFilingStatus;
use App\Modules\Taxation\Domain\Enums\TaxFilingType;
use App\Modules\Taxation\Domain\Models\TaxFiling;
use App\Modules\Taxation\Domain\ValueObjects\VatFilingPeriod;

final class EloquentTaxFilingArchive implements TaxFilingArchive
{
    public function hasFiled(string $ownerId, TaxFilingType $type, VatFilingPeriod $period): bool
    {
        // withoutGlobalScope + explicit owner: the one caller is a console
        // command walking every account, where auth() holds nothing.
        return TaxFiling::withoutGlobalScope('user')
            ->where('user_id', $ownerId)
            ->where('type', $type->value)
            ->where('period_year', $period->year)
            ->where('period_quarter', $period->quarter)
            ->where('period_month', $period->month)
            ->whereIn('status', [TaxFilingStatus::Generated->value, TaxFilingStatus::Filed->value])
            ->exists();
    }
}
