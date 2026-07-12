<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Services;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Calendar\Domain\Models\Event;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplate;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Pricing\Domain\Models\PriceList;
use App\Modules\Pricing\Domain\Models\Rate;
use App\Modules\TimeTracking\Domain\Models\ExchangeRate;
use App\Modules\TimeTracking\Domain\Models\Expense;
use App\Modules\TimeTracking\Domain\Models\TimeEntry;

/**
 * Assembles a complete export of an account's data (GDPR data portability).
 * Every query is scoped explicitly via forUser($ownerId) so it works outside
 * an authenticated request context too.
 */
class AccountExportService
{
    /**
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        $ownerId = $user->accountOwnerId();
        $owner = $user->accountOwner();

        return [
            'exported_at' => now()->toISOString(),
            'profile' => $owner->toArray(),
            'clients' => Client::forUser($ownerId)->with('contactPersons')->get()->toArray(),
            'orders' => Order::forUser($ownerId)->with(['items', 'notes', 'attachments'])->get()
                ->map(fn (Order $order): array => [
                    ...$order->toArray(),
                    'attachments' => $order->attachments->map(fn ($attachment): array => [
                        'id' => $attachment->id,
                        'filename' => $attachment->filename,
                        'label' => $attachment->label,
                        'mime_type' => $attachment->mime_type,
                        'size_bytes' => $attachment->size_bytes,
                        'created_at' => $attachment->created_at?->toISOString(),
                    ])->toArray(),
                ])->toArray(),
            'rates' => Rate::forUser($ownerId)->get()->toArray(),
            'price_lists' => PriceList::forUser($ownerId)->with('items')->get()->toArray(),
            'time_entries' => TimeEntry::forUser($ownerId)->get()->toArray(),
            'expenses' => Expense::forUser($ownerId)->get()->toArray(),
            'exchange_rates' => ExchangeRate::query()->where('user_id', $ownerId)->get()->toArray(),
            'bank_accounts' => BankAccount::forUser($ownerId)->get()->toArray(),
            'invoices' => Invoice::forUser($ownerId)->with(['items', 'payments'])->get()->toArray(),
            'recurring_invoice_templates' => RecurringInvoiceTemplate::forUser($ownerId)->with('items')->get()->toArray(),
            'supplier_invoices' => SupplierInvoice::forUser($ownerId)->with('vatLines')->get()->toArray(),
            'calendar_events' => Event::forUser($ownerId)->get()->toArray(),
        ];
    }
}
