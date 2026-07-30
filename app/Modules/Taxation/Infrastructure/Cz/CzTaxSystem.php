<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Cz;

use App\Modules\Shared\Enums\VatStatus;
use App\Modules\Taxation\Domain\Contracts\AccountingExportBuilder;
use App\Modules\Taxation\Domain\Contracts\ControlStatementBuilder;
use App\Modules\Taxation\Domain\Contracts\EuSalesListBuilder;
use App\Modules\Taxation\Domain\Contracts\IncomeTaxReturnCalculator;
use App\Modules\Taxation\Domain\Contracts\TaxSystem;
use App\Modules\Taxation\Domain\Contracts\VatRegimeResolver;
use App\Modules\Taxation\Domain\Enums\TaxResidency;
use App\Modules\Taxation\Domain\Rules\ValidCzDic;
use App\Modules\Taxation\Domain\Rules\ValidCzIco;
use Illuminate\Contracts\Validation\ValidationRule;

final readonly class CzTaxSystem implements TaxSystem
{
    /**
     * @param  list<AccountingExportBuilder>  $exportBuilders  tagged
     *                                                         'taxation.export_builders.cz' — empty in OSS, where the
     *                                                         Accounting module (Pohoda/ISDOC) doesn't exist
     */
    public function __construct(
        private CzVatRegimeResolver $vatRegimeResolver,
        private CzControlStatementService $controlStatementBuilder,
        private CzEuSalesListBuilder $euSalesListBuilder,
        private array $exportBuilders,
        private CzIncomeTaxReturnCalculator $incomeTaxReturnCalculator,
    ) {}

    public function residency(): TaxResidency
    {
        return TaxResidency::Cz;
    }

    public function vatRateCatalog(): array
    {
        return [0, 12, 21];
    }

    public function defaultVatRate(): float
    {
        return 21.0;
    }

    public function vatIdPrefix(): string
    {
        return 'CZ';
    }

    public function taxLabels(VatStatus $status): array
    {
        return CzTaxLabels::labelsFor($status);
    }

    public function documentLabels(): array
    {
        return ['invoice' => 'Faktura', 'variable_symbol' => 'Variabilní symbol'];
    }

    public function vatRegimeResolver(): VatRegimeResolver
    {
        return $this->vatRegimeResolver;
    }

    public function controlStatementBuilder(): ControlStatementBuilder
    {
        return $this->controlStatementBuilder;
    }

    public function euSalesListBuilder(): EuSalesListBuilder
    {
        return $this->euSalesListBuilder;
    }

    public function accountingExports(): array
    {
        return $this->exportBuilders;
    }

    public function icoRule(): ValidationRule
    {
        return new ValidCzIco;
    }

    public function dicRule(): ValidationRule
    {
        return new ValidCzDic;
    }

    public function vatIdRule(): ValidationRule
    {
        return new ValidCzDic;
    }

    public function incomeTaxReturnCalculator(): IncomeTaxReturnCalculator
    {
        return $this->incomeTaxReturnCalculator;
    }
}
