<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\SettlementAnalytics;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\ValueObjects\SettledInvoice;
use Illuminate\Support\Carbon;

final class EloquentSettlementAnalytics implements SettlementAnalytics
{
    public function settledInvoices(string $ownerId, string $from, string $to, ?string $clientId = null): array
    {
        $query = Invoice::withoutGlobalScope('user')
            ->where('user_id', $ownerId)
            ->where('type', InvoiceType::Invoice->value)
            ->where('status', InvoiceStatus::Paid->value)
            ->whereRaw('COALESCE(taxable_supply_at, issued_at) BETWEEN ? AND ?', [$from, $to])
            ->withMax('payments', 'paid_at')
            ->withCasts(['payments_max_paid_at' => 'date']);

        if ($clientId !== null) {
            $query->where('client_id', $clientId);
        }

        $settled = [];

        foreach ($query->get(['id', 'client_id', 'currency', 'issued_at', 'due_at', 'total']) as $invoice) {
            /** @var Carbon|null $lastPaymentAt */
            $lastPaymentAt = $invoice->getAttribute('payments_max_paid_at');

            if ($lastPaymentAt === null) {
                continue;
            }

            $settled[] = new SettledInvoice(
                clientId: $invoice->client_id,
                currency: $invoice->currency,
                total: (float) $invoice->total,
                issuedAt: $invoice->issued_at,
                dueAt: $invoice->due_at,
                settledAt: $lastPaymentAt,
            );
        }

        return $settled;
    }
}
