<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Enums\AiProvider;
use App\Modules\Invoicing\Domain\Enums\CashDocumentType;
use App\Modules\Invoicing\Domain\Enums\ExchangeRateSource;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Enums\PaymentMethod;
use App\Modules\Shared\Enums\Provenance;
use App\Modules\Shared\Support\EnumCheck;
use App\Modules\Taxation\Domain\Enums\ContributionType;
use App\Modules\Taxation\Domain\Enums\TaxFilingStatus;
use App\Modules\Taxation\Domain\Enums\TaxFilingType;
use App\Modules\Taxation\Domain\Enums\VatFilingFrequency;
use Illuminate\Database\Migrations\Migration;

/**
 * Spells this module's enum columns out as CHECK constraints.
 *
 * Eloquent reads an enum-cast column with `Enum::from()`, which throws on a
 * value it does not recognise — so a string outside the enum is not a merely
 * wrong value, it is a row that can no longer be read at all: a 500 on every
 * list containing it, unrepairable through the API because every write path
 * hydrates the model first. Some columns already had this and most did not,
 * and the gap fell hardest on the ones fed from outside.
 *
 * Values come from the enum itself (Shared\Support\EnumCheck), so the
 * constraint cannot drift from the PHP; tests/Architecture/EnumColumnCheckTest
 * compares the two on every run.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: class-string<BackedEnum>}> */
    private const COLUMNS = [
        ['ai_credentials', 'provider', AiProvider::class],
        ['cash_documents', 'currency', Currency::class],
        ['cash_documents', 'type', CashDocumentType::class],
        ['contribution_payments', 'type', ContributionType::class],
        ['exchange_rates', 'source', ExchangeRateSource::class],
        ['invoice_payments', 'method', PaymentMethod::class],
        ['invoice_payments', 'provenance', Provenance::class],
        ['supplier_invoices', 'provenance', Provenance::class],
        ['tax_filings', 'status', TaxFilingStatus::class],
        ['tax_filings', 'type', TaxFilingType::class],
        ['users', 'vat_filing_frequency', VatFilingFrequency::class],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as [$table, $column, $enum]) {
            EnumCheck::add($table, $column, $enum);
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as [$table, $column]) {
            EnumCheck::drop($table, $column);
        }
    }
};
