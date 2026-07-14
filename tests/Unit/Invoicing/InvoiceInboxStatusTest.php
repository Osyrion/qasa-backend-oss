<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Enums\InvoiceInboxStatus;

it('marks imported and ignored as terminal', function (): void {
    expect(InvoiceInboxStatus::Imported->isTerminal())->toBeTrue()
        ->and(InvoiceInboxStatus::Ignored->isTerminal())->toBeTrue()
        ->and(InvoiceInboxStatus::Pending->isTerminal())->toBeFalse()
        ->and(InvoiceInboxStatus::Failed->isTerminal())->toBeFalse();
});

it('allows converting only pending and failed items', function (): void {
    expect(InvoiceInboxStatus::Pending->canConvert())->toBeTrue()
        ->and(InvoiceInboxStatus::Failed->canConvert())->toBeTrue()
        ->and(InvoiceInboxStatus::Imported->canConvert())->toBeFalse()
        ->and(InvoiceInboxStatus::Ignored->canConvert())->toBeFalse();
});

it('marks pending as pending', function (): void {
    expect(InvoiceInboxStatus::Pending->isPending())->toBeTrue()
        ->and(InvoiceInboxStatus::Failed->isPending())->toBeFalse();
});
