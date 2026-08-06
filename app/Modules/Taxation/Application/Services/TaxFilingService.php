<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Services;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\DTOs\EuSalesListRowData;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Taxation\Application\Contracts\TaxSystemResolverInterface;
use App\Modules\Taxation\Domain\Enums\TaxFilingStatus;
use App\Modules\Taxation\Domain\Enums\TaxFilingType;
use App\Modules\Taxation\Domain\Models\TaxFiling;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4, Part B (docs/plans/SK_VAT_FILING_EDANE_PLAN.md) — generates and
 * archives an immutable TaxFiling snapshot. ControlStatement, EuSalesList
 * and VatReturn are wired to a real generator, all fully derivable
 * server-side from (user, year, quarter/month) alone via the existing
 * TaxSystem contract; IncomeTax stays unwired (wizard-input-driven,
 * deliberately stateless per TAX_RETURN_OSVC_PLAN.md). VatReturn has a real
 * builder for both SK (SkVatReturnService/DphXmlBuilder) and CZ
 * (CzVatReturnService/DphDp3XmlBuilder).
 */
final readonly class TaxFilingService
{
    public function __construct(
        private TaxSystemResolverInterface $taxSystemResolver,
    ) {}

    /**
     * @throws DomainException
     */
    public function generate(User $user, TaxFilingType $type, int $year, ?int $quarter, ?int $month): TaxFiling
    {
        $taxSystem = $this->taxSystemResolver->forUser($user);
        $ownerId = $user->accountOwnerId();

        $content = match ($type) {
            TaxFilingType::ControlStatement => $taxSystem->controlStatementBuilder()->toXml(
                $taxSystem->controlStatementBuilder()->classify($ownerId, $year, $quarter, $month),
                $user,
            ),
            TaxFilingType::EuSalesList => $this->euSalesListJson(
                $taxSystem->euSalesListBuilder()->build($ownerId, $year, $quarter, $month),
            ),
            TaxFilingType::VatReturn => $taxSystem->vatReturnBuilder()->toXml(
                $taxSystem->vatReturnBuilder()->classify($ownerId, $year, $quarter, $month),
                $user,
            ),
            TaxFilingType::IncomeTax => throw DomainException::because(
                __('taxation.tax_filing_type_not_generatable')
            ),
        };

        return DB::transaction(function () use ($ownerId, $type, $taxSystem, $year, $quarter, $month, $content): TaxFiling {
            $previous = TaxFiling::query()
                ->where('user_id', $ownerId)
                ->where('type', $type->value)
                ->where('period_year', $year)
                ->where('period_quarter', $quarter)
                ->where('period_month', $month)
                ->whereNot('status', TaxFilingStatus::Superseded->value)
                ->first();

            if ($previous !== null) {
                $previous->forceFill(['status' => TaxFilingStatus::Superseded->value])->save();
            }

            return TaxFiling::query()->create([
                'user_id' => $ownerId,
                'type' => $type->value,
                'country' => $taxSystem->residency()->value,
                'period_year' => $year,
                'period_quarter' => $quarter,
                'period_month' => $month,
                'content' => $content,
                'sha256' => hash('sha256', $content),
                'status' => TaxFilingStatus::Generated->value,
                'supersedes_id' => $previous?->id,
            ]);
        });
    }

    /**
     * @param  list<EuSalesListRowData>  $rows
     */
    private function euSalesListJson(array $rows): string
    {
        return json_encode(
            array_map(fn (EuSalesListRowData $row): array => [
                'period' => $row->period,
                'vat_id' => $row->vatId,
                'client_name' => $row->clientName,
                'amount' => $row->amount,
                'code' => $row->code,
            ], $rows),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
    }
}
