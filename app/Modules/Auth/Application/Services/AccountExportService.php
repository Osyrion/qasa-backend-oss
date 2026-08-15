<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Services;

use App\Modules\Auth\Application\Contracts\AccountExportContributor;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use App\Modules\Invoicing\Domain\Models\ExchangeRate;
use App\Modules\Invoicing\Domain\Models\Expense;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplate;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Shared\Domain\Models\AccountNotification;
use App\Modules\Shared\Domain\Models\ActivityLog;

/**
 * Assembles a complete export of an account's data (GDPR data portability).
 * Every query is scoped explicitly via forUser($ownerId) so it works outside
 * an authenticated request context too.
 */
class AccountExportService
{
    /**
     * @param  iterable<AccountExportContributor>  $contributors  Sections owned by other modules.
     */
    public function __construct(private readonly iterable $contributors = []) {}

    /**
     * A team member's own personal data, rather than the account's records
     * (docs/plans/GDPR_COMPLIANCE_PLAN.md, phase 4B).
     *
     * The account's clients and invoices are deliberately absent: they are
     * the owner's records, who is the controller for them. A member is a data
     * subject in their own right, but only of the three things here — an
     * export is a right of access, not a permission bypass.
     *
     * **No contributor hook, on purpose.** The plan called for a second
     * method on AccountExportContributor, and writing it showed there is
     * nothing for it to return: time_entries.user_id and trips.user_id are
     * the *account*, and neither table carries member attribution at all
     * (create_trips_table even records that driver_user_id is future work).
     * Every implementation would answer `[]`, so the hook would be an
     * abstraction with no members. Add it the day a premium table can
     * actually name which member a row belongs to.
     *
     * @return array<string, mixed>
     */
    public function buildForMember(User $member): array
    {
        return [
            'exported_at' => now()->toISOString(),
            'profile' => $member->toArray(),
            // What the member did, not what happened on the account.
            'activity_log' => ActivityLog::query()->where('actor_id', $member->id)->get()->toArray(),
            'notifications' => AccountNotification::forRecipient($member->id)->get()->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        $ownerId = $user->accountOwnerId();
        $owner = $user->accountOwner();

        $export = [
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
            'expenses' => Expense::forUser($ownerId)->get()->toArray(),
            'exchange_rates' => ExchangeRate::query()->where('user_id', $ownerId)->get()->toArray(),
            'bank_accounts' => BankAccount::forUser($ownerId)->get()->toArray(),
            'invoices' => Invoice::forUser($ownerId)->with(['items', 'payments'])->get()->toArray(),
            'recurring_invoice_templates' => RecurringInvoiceTemplate::forUser($ownerId)->with('items')->get()->toArray(),
            'supplier_invoices' => SupplierInvoice::forUser($ownerId)->with('vatLines')->get()->toArray(),
            // Shared owns these two, and Shared is core — no contributor
            // needed, unlike the premium sections appended below. Both are
            // the user's own record of what happened on the account, which is
            // squarely what Art. 20 is about.
            'activity_log' => ActivityLog::forUser($ownerId)->get()->toArray(),
            'notifications' => AccountNotification::forUser($ownerId)->get()->toArray(),
        ];

        foreach ($this->contributors as $contributor) {
            $export = [...$export, ...$contributor->exportFor($ownerId)];
        }

        return $export;
    }
}
