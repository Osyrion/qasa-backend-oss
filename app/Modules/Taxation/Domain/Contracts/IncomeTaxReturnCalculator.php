<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Contracts;

use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Taxation\Domain\ValueObjects\TaxReturnInput;
use App\Modules\Taxation\Domain\ValueObjects\TaxReturnResult;

interface IncomeTaxReturnCalculator
{
    public function supportsYear(int $year): bool;

    /**
     * @throws DomainException when the year has no RateTable — an unsupported
     *                         year is a validation error, never a silent fallback to another year.
     */
    public function calculate(TaxReturnInput $input): TaxReturnResult;
}
