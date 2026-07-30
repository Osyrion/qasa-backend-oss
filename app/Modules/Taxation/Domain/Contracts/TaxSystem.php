<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Contracts;

use App\Modules\Shared\Enums\VatStatus;
use App\Modules\Taxation\Domain\Enums\TaxResidency;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Strategy for one tax residency's SK/CZ-specific legislation. The only
 * place any consumer picks between the two is TaxSystemResolver — everyone
 * else programs against this contract. Sadzby, prahy, sekcie výkazov,
 * texty doložiek a labely sa zámerne duplikujú medzi SkTaxSystem a
 * CzTaxSystem — zmena SK zákona sa nikdy nedotkne CZ kódu.
 */
interface TaxSystem
{
    public function residency(): TaxResidency;

    /**
     * @return list<float>
     */
    public function vatRateCatalog(): array;

    public function defaultVatRate(): float;

    public function vatIdPrefix(): string;

    /**
     * @return array<string, string> field name => printed label, for the
     *                               supplier only — a client can be from any
     *                               country, see Invoicing's ClientTaxLabelMap
     */
    public function taxLabels(VatStatus $status): array;

    /**
     * @return array{invoice: string, variable_symbol: string}
     */
    public function documentLabels(): array;

    public function vatRegimeResolver(): VatRegimeResolver;

    public function controlStatementBuilder(): ControlStatementBuilder;

    public function euSalesListBuilder(): EuSalesListBuilder;

    /**
     * @return list<AccountingExportBuilder> SK: Omega; CZ: Pohoda, ISDOC
     */
    public function accountingExports(): array;

    public function icoRule(): ValidationRule;

    public function dicRule(): ValidationRule;

    public function vatIdRule(): ValidationRule;

    public function incomeTaxReturnCalculator(): IncomeTaxReturnCalculator;
}
