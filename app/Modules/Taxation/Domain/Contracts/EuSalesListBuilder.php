<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Contracts;

use App\Modules\Invoicing\Application\DTOs\EuSalesListRowData;

/**
 * SK/CZ súhrnný výkaz (EU sales list): groups a tenant's intra-EU
 * reverse-charged invoices for a period by client VAT ID. Currently
 * identical logic per country — split ahead of divergence (e.g. goods vs.
 * services supply codes), per the plan's "duplication is deliberate" rule.
 */
interface EuSalesListBuilder
{
    /**
     * @return list<EuSalesListRowData>
     */
    public function build(string $userId, int $year, ?int $quarter = null, ?int $month = null): array;
}
