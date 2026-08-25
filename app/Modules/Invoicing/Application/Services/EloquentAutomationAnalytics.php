<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\AutomationAnalytics;
use App\Modules\Invoicing\Domain\Enums\AutomationKind;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Invoicing\Domain\ValueObjects\AutomationTally;
use App\Modules\Shared\Enums\Provenance;

final class EloquentAutomationAnalytics implements AutomationAnalytics
{
    public function tally(string $ownerId, string $from, string $to): array
    {
        return [
            $this->supplierInvoices($ownerId, $from, $to),
            $this->payments($ownerId, $from, $to),
            $this->sends($ownerId, $from, $to),
        ];
    }

    private function supplierInvoices(string $ownerId, string $from, string $to): AutomationTally
    {
        $received = SupplierInvoice::withoutGlobalScope('user')
            ->where('user_id', $ownerId)
            ->whereBetween('created_at', [$from, $to]);

        return new AutomationTally(
            kind: AutomationKind::SupplierInvoices,
            total: (clone $received)->count(),
            automated: $received->where('provenance', '!=', Provenance::Manual->value)->count(),
        );
    }

    private function payments(string $ownerId, string $from, string $to): AutomationTally
    {
        // invoice_payments carries no account of its own — it reaches one
        // through the invoice it settles, which is also where its RLS policy
        // gets the answer.
        $recorded = InvoicePayment::query()
            ->whereRelation('invoice', 'invoices.user_id', $ownerId)
            ->whereBetween('invoice_payments.created_at', [$from, $to]);

        return new AutomationTally(
            kind: AutomationKind::Payments,
            total: (clone $recorded)->count(),
            automated: $recorded->where('provenance', '!=', Provenance::Manual->value)->count(),
        );
    }

    private function sends(string $ownerId, string $from, string $to): AutomationTally
    {
        $emailed = Invoice::withoutGlobalScope('user')
            ->where('user_id', $ownerId)
            ->whereNotNull('emailed_at')
            ->whereBetween('emailed_at', [$from, $to]);

        return new AutomationTally(
            kind: AutomationKind::Sends,
            total: (clone $emailed)->count(),
            automated: $emailed->where('sent_automatically', true)->count(),
        );
    }
}
