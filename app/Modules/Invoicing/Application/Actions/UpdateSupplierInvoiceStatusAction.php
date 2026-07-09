<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Contracts\SupplierInvoiceRepositoryInterface;
use App\Modules\Invoicing\Domain\Enums\SupplierInvoiceStatus;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class UpdateSupplierInvoiceStatusAction
{
    public function __construct(
        private SupplierInvoiceRepositoryInterface $repository,
    ) {}

    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(SupplierInvoice $supplierInvoice, SupplierInvoiceStatus $newStatus, ?string $paidAt = null): SupplierInvoice
    {
        $currentStatus = SupplierInvoiceStatus::from($supplierInvoice->status);

        $this->assertTransition($currentStatus, $newStatus);

        return DB::transaction(function () use ($supplierInvoice, $newStatus, $paidAt): SupplierInvoice {
            // Re-read under a row lock and re-validate: a concurrent request
            // may have advanced the status since the check above.
            $supplierInvoice = SupplierInvoice::query()->lockForUpdate()->whereKey($supplierInvoice->getKey())->firstOrFail();
            $currentStatus = SupplierInvoiceStatus::from($supplierInvoice->status);

            $this->assertTransition($currentStatus, $newStatus);

            $attributes = ['status' => $newStatus->value];

            if ($newStatus === SupplierInvoiceStatus::Received) {
                $supplierInvoice->loadMissing('client');
                $attributes['vendor_snapshot'] = $this->buildVendorSnapshot($supplierInvoice->client);
            }

            if ($newStatus === SupplierInvoiceStatus::Paid) {
                $attributes['paid_at'] = $paidAt ?? now()->toDateString();
            }

            return $this->repository->update($supplierInvoice, $attributes);
        });
    }

    /**
     * @throws DomainException
     */
    private function assertTransition(SupplierInvoiceStatus $from, SupplierInvoiceStatus $to): void
    {
        if (! $from->canTransitionTo($to)) {
            throw DomainException::because(
                __('invoicing.supplier_invoice.status_transition_not_allowed', ['from' => $from->label(), 'to' => $to->label()])
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildVendorSnapshot(?Client $client): ?array
    {
        if ($client === null) {
            return null;
        }

        return [
            'name' => $client->display_name,
            'ico' => $client->ico,
            'dic' => $client->dic,
            'vat_id' => $client->vat_id,
            'is_vat_payer' => $client->is_vat_payer,
            'address' => $client->address,
            'city' => $client->city,
            'postal_code' => $client->postal_code,
            'country' => $client->country,
            'email' => $client->email,
            'phone' => $client->phone,
        ];
    }
}
