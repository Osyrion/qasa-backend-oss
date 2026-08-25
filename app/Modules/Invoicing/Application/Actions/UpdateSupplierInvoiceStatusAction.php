<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Clients\Application\Contracts\ClientDirectory;
use App\Modules\Invoicing\Application\Contracts\SupplierInvoiceRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\UpdateSupplierInvoiceStatusActionInterface;
use App\Modules\Invoicing\Domain\Enums\SupplierInvoiceStatus;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Invoicing\Domain\ValueObjects\DocumentParty;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class UpdateSupplierInvoiceStatusAction implements UpdateSupplierInvoiceStatusActionInterface
{
    public function __construct(
        private SupplierInvoiceRepositoryInterface $repository,
        private ClientDirectory $clients,
    ) {}

    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function transition(string $supplierInvoiceId, SupplierInvoiceStatus $newStatus, ?string $paidAt = null): void
    {
        /** @var SupplierInvoice $supplierInvoice */
        $supplierInvoice = SupplierInvoice::query()->findOrFail($supplierInvoiceId);

        $this->execute($supplierInvoice, $newStatus, $paidAt);
    }

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
                $attributes['vendor_snapshot'] = $this->buildVendorSnapshot($supplierInvoice->client_id, $supplierInvoice->user_id);
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
     * Null where the vendor has since been archived away — the same answer the
     * `client` relation gave when this read the aggregate.
     *
     * @return array<string, mixed>|null
     */
    private function buildVendorSnapshot(string $clientId, string $ownerId): ?array
    {
        $profile = $this->clients->profilesFor([$clientId])[$clientId] ?? null;

        if ($profile === null) {
            return null;
        }

        return DocumentParty::fromProfile($profile, $this->clients->requirePeppolId($clientId, $ownerId))->toSnapshot();
    }
}
