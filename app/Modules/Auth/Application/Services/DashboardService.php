<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Services;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Models\Invoice;

/**
 * Overdue-invoice reminder data for the account owner: which invoices have
 * crossed the reminder threshold and whether each is eligible for a manual
 * "send reminder" action. The KPI/trend figures this service used to compute
 * were superseded by the Statistics module (see OverviewStatisticsService,
 * ReceivablesStatisticsService) and were removed with them; this is the one
 * piece with no equivalent there.
 */
class DashboardService
{
    /**
     * @return array{threshold_days: int, items: array<int, array<string, mixed>>}
     */
    public function getStats(User $user): array
    {
        return $this->overdueReminders($user);
    }

    /**
     * Overdue invoices past the user's reminder threshold, with a per-item
     * can_remind flag mirroring RemindInvoiceAction's guards so the frontend
     * can render "send reminder" buttons without a round trip.
     *
     * @return array{threshold_days: int, items: array<int, array<string, mixed>>}
     */
    private function overdueReminders(User $user): array
    {
        $threshold = $user->overdue_reminder_days;
        $cutoff = now()->startOfDay()->subDays($threshold)->toDateString();
        $cooldownDays = (int) config('invoicing.reminder_cooldown_days', 3);

        $invoices = Invoice::withoutGlobalScope('user')
            ->where('user_id', $user->accountOwnerId())
            ->whereIn('status', ['issued', 'sent', 'reminded'])
            ->where('due_at', '<', $cutoff)
            ->withSum('payments as payments_sum_amount', 'amount')
            ->with('client:id,client_type,title,name,surname,company_name,email')
            ->orderBy('due_at')
            ->limit(20)
            ->get();

        return [
            'threshold_days' => $threshold,
            'items' => $invoices
                ->map(fn (Invoice $invoice): array => $this->overdueReminderItem($invoice, $cooldownDays))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function overdueReminderItem(Invoice $invoice, int $cooldownDays): array
    {
        $email = $invoice->client_snapshot['email'] ?? $invoice->client?->email;
        $nextAllowed = $invoice->last_reminded_at?->copy()->addDays($cooldownDays);

        [$canRemind, $reason] = match (true) {
            ! in_array($invoice->status, [InvoiceStatus::Sent, InvoiceStatus::Reminded], true) => [
                false,
                __('invoicing.reminder_only_for_sent', ['status' => $invoice->statusEnum()->label()]),
            ],
            $nextAllowed !== null && $nextAllowed->isFuture() => [
                false,
                __('invoicing.reminder_cooldown', ['next_allowed' => $nextAllowed->format('d.m.Y H:i')]),
            ],
            $email === null || $email === '' => [
                false,
                __('invoicing.client_email_missing_for_reminder'),
            ],
            default => [true, null],
        };

        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'client_name' => $invoice->client_snapshot['name'] ?? $invoice->client?->display_name,
            'currency' => $invoice->currency->value,
            'balance' => round((float) $invoice->total - (float) $invoice->getAttribute('payments_sum_amount'), 2),
            'due_at' => $invoice->due_at->toDateString(),
            'days_overdue' => (int) $invoice->due_at->diffInDays(now()->startOfDay()),
            'last_reminded_at' => $invoice->last_reminded_at?->toISOString(),
            'reminder_count' => $invoice->reminder_count,
            'can_remind' => $canRemind,
            'can_remind_reason' => $reason,
        ];
    }
}
