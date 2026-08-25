<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\VatControlStatementSourceInterface;
use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Invoicing\Domain\Models\SupplierInvoiceVatLine;
use App\Modules\Invoicing\Domain\Services\VatRecapCalculator;
use App\Modules\Invoicing\Domain\ValueObjects\DocumentParty;
use App\Modules\Invoicing\Domain\ValueObjects\VatControlStatementIssuedDocument;
use App\Modules\Invoicing\Domain\ValueObjects\VatControlStatementReceivedDocument;
use App\Modules\Invoicing\Domain\ValueObjects\VatControlStatementRowData;
use App\Modules\Invoicing\Domain\ValueObjects\VatRecapRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Document collection for the VAT control statement (SK: kontrolný výkaz
 * DPH / CZ: kontrolní hlášení) — the country-agnostic half. Classifying
 * collected documents into a country's own section codes and thresholds is
 * Taxation's Sk/CzControlStatementService; this class queries and reduces.
 *
 * Reducing is the part that moved here. Reading `client_snapshot['vat_id']`,
 * choosing the taxable-supply date over the issue date and recapping the items
 * per VAT rate are all statements about what an invoice *is*, and they were
 * written out twice in Taxation — once per residency, with the obvious risk
 * that a fix would land in one copy.
 */
class VatControlStatementService implements VatControlStatementSourceInterface
{
    public function __construct(private readonly VatRecapCalculator $recapCalculator) {}

    /**
     * @param  list<string>  $months  "Y-m" months in scope
     * @return list<VatControlStatementIssuedDocument>
     */
    public function collectIssuedDocuments(string $userId, array $months): array
    {
        return array_values($this->collectIssuedInvoices($userId, $months)->map(function (Invoice $invoice): VatControlStatementIssuedDocument {
            $party = DocumentParty::fromSnapshot($invoice->client_snapshot);
            $documentNumber = (string) $invoice->invoice_number;
            $date = ($invoice->taxable_supply_at ?? $invoice->issued_at)->format('Y-m-d');
            $partnerName = $party->name ?? '';
            $partnerTaxId = $party?->taxIdForStatement();

            return new VatControlStatementIssuedDocument(
                documentNumber: $documentNumber,
                date: $date,
                partnerName: $partnerName,
                partnerTaxId: $partnerTaxId,
                reverseChargeMode: $invoice->reverse_charge_mode,
                isCreditNote: $invoice->type === InvoiceType::CreditNote,
                relatedDocumentNumber: $invoice->relatedInvoice?->invoice_number,
                grossTotal: (float) $invoice->total,
                currency: $invoice->currency,
                exchangeRate: $invoice->exchange_rate_snapshot !== null ? (float) $invoice->exchange_rate_snapshot : null,
                rows: array_map(
                    static fn (VatRecapRow $row): VatControlStatementRowData => new VatControlStatementRowData(
                        $documentNumber, $date, $partnerName, $partnerTaxId, $row->rate, $row->base, $row->vat,
                    ),
                    $this->recapCalculator->recap($invoice),
                ),
            );
        })->all());
    }

    /**
     * The rows themselves, for Invoicing's own readers.
     *
     * Public but not on the contract: `VatReturnAggregationService` sums the
     * same documents into period totals and lives in this module, where
     * holding the aggregate is what the module is for. Nothing outside gets
     * this — that is the whole point of the two shapes.
     *
     * @param  list<string>  $months  "Y-m" months in scope
     * @return Collection<int, Invoice>
     */
    public function collectIssuedInvoices(string $userId, array $months): Collection
    {
        return $this->restrictToMonths(
            Invoice::withoutGlobalScope('user')
                ->where('user_id', $userId)
                ->whereNotIn('status', ['draft', 'cancelled'])
                ->whereNotIn('type', [InvoiceType::Storno->value, InvoiceType::Proforma->value])
                // The recap reads the items and the credit-note branch reads
                // the corrected document; without these the collection is one
                // query per invoice per relation.
                ->with(['items', 'relatedInvoice']),
            $months,
        )->get();
    }

    /**
     * @param  list<string>  $months  "Y-m" months in scope
     * @return list<VatControlStatementReceivedDocument>
     */
    public function collectReceivedDocuments(string $userId, array $months): array
    {
        return array_values($this->collectReceivedInvoices($userId, $months)->map(function (SupplierInvoice $invoice): VatControlStatementReceivedDocument {
            $party = DocumentParty::fromSnapshot($invoice->vendor_snapshot);
            $documentNumber = $invoice->supplier_invoice_number;
            $date = ($invoice->taxable_supply_at ?? $invoice->issued_at)->format('Y-m-d');
            $partnerName = $party->name ?? '';
            $partnerTaxId = $party?->taxIdForStatement();

            /** @var list<VatControlStatementRowData> $rows */
            $rows = array_values($invoice->vatLines->map(
                static fn (SupplierInvoiceVatLine $line): VatControlStatementRowData => new VatControlStatementRowData(
                    $documentNumber, $date, $partnerName, $partnerTaxId,
                    (float) $line->vat_rate, (float) $line->base, (float) $line->vat_amount,
                ),
            )->all());

            return new VatControlStatementReceivedDocument(
                documentNumber: $documentNumber,
                date: $date,
                partnerName: $partnerName,
                partnerTaxId: $partnerTaxId,
                selfAssessed: $invoice->vat_regime->isSelfAssessed(),
                grossTotal: (float) $invoice->total,
                currency: $invoice->currency,
                exchangeRate: $invoice->exchange_rate !== null ? (float) $invoice->exchange_rate : null,
                rows: $rows,
            );
        })->all());
    }

    /**
     * The received rows themselves — see collectIssuedInvoices() for why this
     * exists alongside the value-returning method.
     *
     * @param  list<string>  $months  "Y-m" months in scope
     * @return Collection<int, SupplierInvoice>
     */
    public function collectReceivedInvoices(string $userId, array $months): Collection
    {
        return $this->restrictToMonths(
            SupplierInvoice::withoutGlobalScope('user')
                ->where('user_id', $userId)
                ->whereNotIn('status', ['draft', 'cancelled'])
                ->with('vatLines'),
            $months,
        )->get();
    }

    /**
     * Narrow a query to the DUZP months in scope.
     *
     * Two predicates rather than one. The BETWEEN is what makes this
     * indexable — it is expressed over the bare
     * COALESCE(taxable_supply_at, issued_at), which is exactly what
     * invoices_user_effective_date_idx covers, whereas the to_char form
     * cannot use that index. The IN then does the precise selection, so the
     * result stays identical even if a caller ever passes months that are
     * not contiguous.
     *
     * @template TModel of Invoice|SupplierInvoice
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $months
     * @return Builder<TModel>
     */
    private function restrictToMonths(Builder $query, array $months): Builder
    {
        if ($months === []) {
            // What the in_array() filter did before: nothing in scope, so
            // nothing comes back.
            return $query->whereRaw('1 = 0');
        }

        $effectiveDate = 'COALESCE(taxable_supply_at, issued_at)';

        return $query
            ->whereRaw("{$effectiveDate} BETWEEN ? AND ?", [
                Carbon::parse(min($months).'-01')->startOfMonth()->toDateString(),
                Carbon::parse(max($months).'-01')->endOfMonth()->toDateString(),
            ])
            ->whereIn(DB::raw("to_char({$effectiveDate}, 'YYYY-MM')"), $months);
    }

    /**
     * @return list<string> "Y-m" months in scope
     */
    public function monthsInScope(int $year, ?int $quarter, ?int $month): array
    {
        if ($month !== null) {
            return [sprintf('%04d-%02d', $year, $month)];
        }

        if ($quarter !== null) {
            $start = ($quarter - 1) * 3 + 1;

            return array_map(
                static fn (int $m): string => sprintf('%04d-%02d', $year, $m),
                range($start, $start + 2),
            );
        }

        return array_map(
            static fn (int $m): string => sprintf('%04d-%02d', $year, $m),
            range(1, 12),
        );
    }
}
