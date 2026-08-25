<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\Models\Invoice;
use Illuminate\Database\Eloquent\Collection;

/**
 * Source of the intra-EU supply rows an EU sales list is built from.
 *
 * The same seam as VatControlStatementSourceInterface: the layout is
 * Taxation's, the invoices are Invoicing's.
 */
interface EuSalesListSourceInterface
{
    /**
     * @param  list<string>  $months  "Y-m" months in scope
     * @return Collection<int, Invoice>
     */
    public function collect(string $userId, array $months): Collection;

    /**
     * @return list<string> "Y-m" months in scope
     */
    public function monthsInScope(int $year, ?int $quarter, ?int $month): array;
}
