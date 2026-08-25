<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Contracts;

use App\Modules\Taxation\Domain\Enums\TaxFilingType;
use App\Modules\Taxation\Domain\ValueObjects\VatFilingPeriod;

/**
 * What an account has already filed.
 *
 * One question, asked by the reminder: is there a generated or filed document
 * of this type for this period? Everything else about a filing — its XML, its
 * totals, when it was submitted — is ours, and a reminder that could read it
 * would be reading a tax document to decide whether to send an email.
 */
interface TaxFilingArchive
{
    /**
     * Whether the account has a filing of this type for this period that has
     * got as far as generated or filed. A draft does not count: it is the
     * intention to file, not the filing.
     */
    public function hasFiled(string $ownerId, TaxFilingType $type, VatFilingPeriod $period): bool;
}
