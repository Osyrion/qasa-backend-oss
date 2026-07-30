<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Enums\SupplierInvoiceStatus;

it('only allows the modeled forward transitions', function (SupplierInvoiceStatus $from, array $allowed): void {
    foreach (SupplierInvoiceStatus::cases() as $to) {
        expect($from->canTransitionTo($to))->toBe(in_array($to, $allowed, true));
    }
})->with([
    'draft' => [SupplierInvoiceStatus::Draft, [SupplierInvoiceStatus::Received]],
    'received' => [SupplierInvoiceStatus::Received, [SupplierInvoiceStatus::Booked, SupplierInvoiceStatus::Paid, SupplierInvoiceStatus::Cancelled]],
    'booked' => [SupplierInvoiceStatus::Booked, [SupplierInvoiceStatus::Paid, SupplierInvoiceStatus::Cancelled]],
    'paid' => [SupplierInvoiceStatus::Paid, []],
    'cancelled' => [SupplierInvoiceStatus::Cancelled, []],
]);

it('marks paid and cancelled as terminal', function (): void {
    expect(SupplierInvoiceStatus::Paid->isTerminal())->toBeTrue()
        ->and(SupplierInvoiceStatus::Cancelled->isTerminal())->toBeTrue()
        ->and(SupplierInvoiceStatus::Draft->isTerminal())->toBeFalse()
        ->and(SupplierInvoiceStatus::Received->isTerminal())->toBeFalse()
        ->and(SupplierInvoiceStatus::Booked->isTerminal())->toBeFalse();
});

it('only draft is editable', function (): void {
    expect(SupplierInvoiceStatus::Draft->isEditable())->toBeTrue();

    foreach ([SupplierInvoiceStatus::Received, SupplierInvoiceStatus::Booked, SupplierInvoiceStatus::Paid, SupplierInvoiceStatus::Cancelled] as $status) {
        expect($status->isEditable())->toBeFalse();
    }
});
