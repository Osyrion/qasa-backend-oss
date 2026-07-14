<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Enums\ReverseChargeMode;
use App\Modules\Invoicing\Domain\Services\InvoiceVatRegimeResolver;
use App\Modules\Shared\Enums\VatStatus;
use App\Modules\Shared\Exceptions\DomainException;

beforeEach(function (): void {
    $this->resolver = new InvoiceVatRegimeResolver;
});

it('rejects reverse charge requested by a non-payer', function (): void {
    $client = Client::factory()->make(['country' => 'SK']);

    $this->resolver->resolve(VatStatus::NonPayer, 'SK', $client, true);
})->throws(DomainException::class);

it('never applies reverse charge for a non-payer without a request', function (): void {
    $client = Client::factory()->make(['country' => 'SK']);

    $decision = $this->resolver->resolve(VatStatus::NonPayer, 'SK', $client, false);

    expect($decision->reverseCharge)->toBeFalse()
        ->and($decision->mode)->toBeNull();
});

it('auto-applies EU reverse charge for an identified person with an EU client with a VAT ID', function (): void {
    $client = Client::factory()->make(['country' => 'DE', 'vat_id' => 'DE123456789']);

    $decision = $this->resolver->resolve(VatStatus::Identified, 'SK', $client, false);

    expect($decision->reverseCharge)->toBeTrue()
        ->and($decision->mode)->toBe(ReverseChargeMode::Eu);
});

it('never applies reverse charge for an identified person with a non-EU client', function (): void {
    $client = Client::factory()->make(['country' => 'US', 'vat_id' => null]);

    $decision = $this->resolver->resolve(VatStatus::Identified, 'SK', $client, false);

    expect($decision->reverseCharge)->toBeFalse()
        ->and($decision->mode)->toBeNull();
});

it('never applies reverse charge for an identified person with a domestic client', function (): void {
    $client = Client::factory()->make(['country' => 'SK']);

    $decision = $this->resolver->resolve(VatStatus::Identified, 'SK', $client, false);

    expect($decision->reverseCharge)->toBeFalse()
        ->and($decision->mode)->toBeNull();
});

it('applies domestic reverse charge for a payer only when the client allows it and it is requested', function (): void {
    $client = Client::factory()->make(['country' => 'SK', 'reverse_charge_allowed' => true]);

    $decision = $this->resolver->resolve(VatStatus::Payer, 'SK', $client, true);

    expect($decision->reverseCharge)->toBeTrue()
        ->and($decision->mode)->toBe(ReverseChargeMode::Domestic);
});

it('rejects a requested domestic reverse charge when the client does not allow it', function (): void {
    $client = Client::factory()->make(['country' => 'SK', 'reverse_charge_allowed' => false]);

    $this->resolver->resolve(VatStatus::Payer, 'SK', $client, true);
})->throws(DomainException::class);

it('auto-applies EU reverse charge for a payer with an EU client with a VAT ID regardless of the request flag', function (): void {
    $client = Client::factory()->make(['country' => 'DE', 'vat_id' => 'DE123456789']);

    $decision = $this->resolver->resolve(VatStatus::Payer, 'SK', $client, false);

    expect($decision->reverseCharge)->toBeTrue()
        ->and($decision->mode)->toBe(ReverseChargeMode::Eu);
});

it('does not apply EU reverse charge without a client VAT ID', function (): void {
    $client = Client::factory()->make(['country' => 'DE', 'vat_id' => null]);

    $decision = $this->resolver->resolve(VatStatus::Payer, 'SK', $client, false);

    expect($decision->reverseCharge)->toBeFalse();
});

it('never applies reverse charge for a payer with a plain domestic client and no request', function (): void {
    $client = Client::factory()->make(['country' => 'SK', 'reverse_charge_allowed' => false]);

    $decision = $this->resolver->resolve(VatStatus::Payer, 'SK', $client, false);

    expect($decision->reverseCharge)->toBeFalse()
        ->and($decision->mode)->toBeNull();
});
