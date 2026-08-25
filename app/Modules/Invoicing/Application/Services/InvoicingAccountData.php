<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Auth\Application\Contracts\AccountExportContributor;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use App\Modules\Invoicing\Domain\Models\ExchangeRate;
use App\Modules\Invoicing\Domain\Models\Expense;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplate;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;

/**
 * Invoicing's six sections of the GDPR account export.
 *
 * Which relations come along is a fact about these tables — an invoice
 * without its items and payments is not the account's record of it — and
 * that fact belongs here rather than in Auth's exporter.
 */
final class InvoicingAccountData implements AccountExportContributor
{
    /**
     * @return array<string, mixed>
     */
    public function exportFor(string $ownerId): array
    {
        return [
            'expenses' => Expense::forUser($ownerId)->get()->toArray(),
            // Not forUser(): the shared system rates carry no user_id, and an
            // account exports only the ones it entered itself.
            'exchange_rates' => ExchangeRate::query()->where('user_id', $ownerId)->get()->toArray(),
            'bank_accounts' => BankAccount::forUser($ownerId)->get()->toArray(),
            'invoices' => Invoice::forUser($ownerId)->with(['items', 'payments'])->get()->toArray(),
            'recurring_invoice_templates' => RecurringInvoiceTemplate::forUser($ownerId)->with('items')->get()->toArray(),
            'supplier_invoices' => SupplierInvoice::forUser($ownerId)->with('vatLines')->get()->toArray(),
        ];
    }
}
