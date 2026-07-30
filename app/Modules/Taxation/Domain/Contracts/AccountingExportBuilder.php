<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Contracts;

/**
 * Marker + identity contract for a tenant's available accounting export
 * formats (SK: Omega; CZ: Pohoda, ISDOC). Each builder's actual build
 * method differs by format (Omega: separate issued/received methods;
 * Pohoda: one iterable-of-invoices method; ISDOC: one single-invoice
 * method) — deliberately not unified, callers resolve the concrete builder
 * by class after checking `format()` against `TaxSystem::accountingExports()`.
 */
interface AccountingExportBuilder
{
    public function format(): string;
}
