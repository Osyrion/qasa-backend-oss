<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Sk;

use App\Modules\Shared\Enums\VatStatus;
use App\Modules\Taxation\Domain\Contracts\AccountingExportBuilder;
use App\Modules\Taxation\Domain\Contracts\ControlStatementBuilder;
use App\Modules\Taxation\Domain\Contracts\EuSalesListBuilder;
use App\Modules\Taxation\Domain\Contracts\IncomeTaxReturnCalculator;
use App\Modules\Taxation\Domain\Contracts\TaxSystem;
use App\Modules\Taxation\Domain\Contracts\VatRegimeResolver;
use App\Modules\Taxation\Domain\Contracts\VatReturnBuilder;
use App\Modules\Taxation\Domain\Enums\TaxResidency;
use App\Modules\Taxation\Domain\Rules\ValidSkDic;
use App\Modules\Taxation\Domain\Rules\ValidSkIco;
use App\Modules\Taxation\Domain\Rules\ValidSkVatId;
use Illuminate\Contracts\Validation\ValidationRule;

final readonly class SkTaxSystem implements TaxSystem
{
    /**
     * @param  list<AccountingExportBuilder>  $exportBuilders  tagged
     *                                                         'taxation.export_builders.sk' — empty in OSS, where the
     *                                                         Accounting module (Omega) doesn't exist
     */
    public function __construct(
        private SkVatRegimeResolver $vatRegimeResolver,
        private SkControlStatementService $controlStatementBuilder,
        private SkEuSalesListBuilder $euSalesListBuilder,
        private array $exportBuilders,
        private SkIncomeTaxReturnCalculator $incomeTaxReturnCalculator,
        private SkVatReturnService $vatReturnBuilder,
    ) {}

    public function residency(): TaxResidency
    {
        return TaxResidency::Sk;
    }

    public function vatRateCatalog(): array
    {
        return [0, 5, 10, 23];
    }

    public function defaultVatRate(): float
    {
        return 23.0;
    }

    public function vatIdPrefix(): string
    {
        return 'SK';
    }

    public function taxLabels(VatStatus $status): array
    {
        return SkTaxLabels::labelsFor($status);
    }

    public function documentLabels(): array
    {
        return ['invoice' => 'Faktúra', 'variable_symbol' => 'Variabilný symbol'];
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

    public function vatReturnBuilder(): VatReturnBuilder
    {
        return $this->vatReturnBuilder;
    }

    public function accountingExports(): array
    {
        return $this->exportBuilders;
    }

    public function icoRule(): ValidationRule
    {
        return new ValidSkIco;
    }

    public function dicRule(): ValidationRule
    {
        return new ValidSkDic;
    }

    public function vatIdRule(): ValidationRule
    {
        return new ValidSkVatId;
    }

    public function incomeTaxReturnCalculator(): IncomeTaxReturnCalculator
    {
        return $this->incomeTaxReturnCalculator;
    }
}
