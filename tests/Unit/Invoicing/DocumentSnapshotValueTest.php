<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\ValueObjects\DocumentBankAccount;
use App\Modules\Invoicing\Domain\ValueObjects\DocumentParty;
use App\Modules\Invoicing\Domain\ValueObjects\DocumentSupplier;
use App\Modules\Shared\Domain\ValueObjects\PartyProfile;
use App\Modules\Shared\Enums\Currency;

it('round-trips a party through the snapshot shape the columns already hold', function (): void {
    $party = DocumentParty::fromProfile(
        new PartyProfile(
            name: 'Klient s.r.o.',
            email: 'klient@example.com',
            phone: '+421900000000',
            ico: '87654321',
            dic: '3030303030',
            vatId: 'SK3030303030',
            isVatPayer: true,
            address: 'Nová 5',
            city: 'Košice',
            postalCode: '04001',
            country: 'SK',
            // Neither of these belongs on paper, and neither survives the
            // round trip — the point of the value being its own type.
            currency: Currency::EUR,
            reverseChargeAllowed: true,
        ),
        peppolId: '0245:87654321',
    );

    $snapshot = $party->toSnapshot();

    expect($snapshot)->toBe([
        'name' => 'Klient s.r.o.',
        'ico' => '87654321',
        'dic' => '3030303030',
        'vat_id' => 'SK3030303030',
        'is_vat_payer' => true,
        'address' => 'Nová 5',
        'city' => 'Košice',
        'postal_code' => '04001',
        'country' => 'SK',
        'email' => 'klient@example.com',
        'phone' => '+421900000000',
        'peppol_id' => '0245:87654321',
    ]);

    expect(DocumentParty::fromSnapshot($snapshot))->toEqual($party);
});

/*
 * The oldest documents in the database were written by code that recorded
 * fewer fields — the quote snapshot had no peppol_id at all until the three
 * issuing points were unified. Reading one must not throw and must not invent
 * anything.
 */
it('reads a snapshot written before half its keys existed', function (): void {
    $party = DocumentParty::fromSnapshot(['name' => 'Starý klient', 'ico' => '12345678']);

    expect($party)->not->toBeNull()
        ->and($party?->name)->toBe('Starý klient')
        ->and($party?->ico)->toBe('12345678')
        ->and($party?->country)->toBeNull()
        ->and($party?->peppolId)->toBeNull()
        ->and($party?->isVatPayer)->toBeFalse();
});

it('has nothing to say about a document that froze no counterparty', function (): void {
    expect(DocumentParty::fromSnapshot(null))->toBeNull()
        ->and(DocumentParty::fromSnapshot([]))->toBeNull()
        ->and(DocumentSupplier::fromSnapshot(null))->toBeNull()
        ->and(DocumentBankAccount::fromSnapshot(null))->toBeNull();
});

it('names a party on a statement by VAT number, then income-tax number, then not at all', function (): void {
    $payer = DocumentParty::fromSnapshot(['name' => 'A', 'is_vat_payer' => true, 'vat_id' => 'SK1', 'dic' => '2']);
    $nonPayer = DocumentParty::fromSnapshot(['name' => 'B', 'is_vat_payer' => false, 'vat_id' => 'SK1', 'dic' => '2']);
    $neither = DocumentParty::fromSnapshot(['name' => 'C']);

    expect($payer?->taxIdForStatement())->toBe('SK1')
        ->and($nonPayer?->taxIdForStatement())->toBe('2')
        ->and($neither?->taxIdForStatement())->toBeNull();
});

it('reads the supplier and bank snapshots the issuing points write', function (): void {
    $supplier = DocumentSupplier::fromSnapshot([
        'name' => 'Moja firma', 'ico' => '12345678', 'is_vat_payer' => true,
        'vat_status' => 'payer', 'country' => 'SK', 'logo_path' => 'logos/a.png',
    ]);

    expect($supplier?->name)->toBe('Moja firma')
        ->and($supplier?->vatStatus)->toBe('payer')
        ->and($supplier?->logoPath)->toBe('logos/a.png')
        ->and($supplier?->invoiceFooterText)->toBeNull();

    $bank = DocumentBankAccount::fromSnapshot([
        'label' => 'Bežný', 'bank_name' => 'SLSP', 'account_number' => '123456789/0900',
        'iban' => 'SK1234567890123456789012', 'bic' => 'GIBASKBX', 'currency' => 'EUR',
    ]);

    expect($bank?->iban)->toBe('SK1234567890123456789012')
        ->and($bank?->bic)->toBe('GIBASKBX')
        ->and($bank?->currency)->toBe('EUR');
});
