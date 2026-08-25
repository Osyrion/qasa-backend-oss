<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Enums\ReverseChargeMode;
use App\Modules\Shared\Enums\VatStatus;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Taxation\Infrastructure\Sk\SkVatRegimeResolver;

beforeEach(function (): void {
    $this->resolver = new SkVatRegimeResolver;
});

it('rejects reverse charge requested by a non-payer', function (): void {
    $client = Client::factory()->make(['country' => 'SK']);

    $this->resolver->resolve(VatStatus::NonPayer, $client->profile(), true);
})->throws(DomainException::class);

it('never applies reverse charge for a non-payer without a request', function (): void {
    $client = Client::factory()->make(['country' => 'SK']);

    $decision = $this->resolver->resolve(VatStatus::NonPayer, $client->profile(), false);

    expect($decision->reverseCharge)->toBeFalse()
        ->and($decision->mode)->toBeNull();
});

it('auto-applies EU reverse charge for an identified person with an EU client with a VAT ID', function (): void {
    $client = Client::factory()->make(['country' => 'DE', 'vat_id' => 'DE123456789']);

    $decision = $this->resolver->resolve(VatStatus::Identified, $client->profile(), false);

    expect($decision->reverseCharge)->toBeTrue()
        ->and($decision->mode)->toBe(ReverseChargeMode::Eu);
});

it('never applies reverse charge for an identified person with a non-EU client', function (): void {
    $client = Client::factory()->make(['country' => 'US', 'vat_id' => null]);

    $decision = $this->resolver->resolve(VatStatus::Identified, $client->profile(), false);

    expect($decision->reverseCharge)->toBeFalse()
        ->and($decision->mode)->toBeNull();
});

it('never applies reverse charge for an identified person with a domestic client', function (): void {
    $client = Client::factory()->make(['country' => 'SK']);

    $decision = $this->resolver->resolve(VatStatus::Identified, $client->profile(), false);

    expect($decision->reverseCharge)->toBeFalse()
        ->and($decision->mode)->toBeNull();
});

it('applies domestic reverse charge for a payer only when the client allows it and it is requested', function (): void {
    $client = Client::factory()->make(['country' => 'SK', 'reverse_charge_allowed' => true]);

    $decision = $this->resolver->resolve(VatStatus::Payer, $client->profile(), true);

    expect($decision->reverseCharge)->toBeTrue()
        ->and($decision->mode)->toBe(ReverseChargeMode::Domestic);
});

it('rejects a requested domestic reverse charge when the client does not allow it', function (): void {
    $client = Client::factory()->make(['country' => 'SK', 'reverse_charge_allowed' => false]);

    $this->resolver->resolve(VatStatus::Payer, $client->profile(), true);
})->throws(DomainException::class);

it('auto-applies EU reverse charge for a payer with an EU client with a VAT ID regardless of the request flag', function (): void {
    $client = Client::factory()->make(['country' => 'DE', 'vat_id' => 'DE123456789']);

    $decision = $this->resolver->resolve(VatStatus::Payer, $client->profile(), false);

    expect($decision->reverseCharge)->toBeTrue()
        ->and($decision->mode)->toBe(ReverseChargeMode::Eu);
});

it('does not apply EU reverse charge without a client VAT ID', function (): void {
    $client = Client::factory()->make(['country' => 'DE', 'vat_id' => null]);

    $decision = $this->resolver->resolve(VatStatus::Payer, $client->profile(), false);

    expect($decision->reverseCharge)->toBeFalse();
});

it('never applies reverse charge for a payer with a plain domestic client and no request', function (): void {
    $client = Client::factory()->make(['country' => 'SK', 'reverse_charge_allowed' => false]);

    $decision = $this->resolver->resolve(VatStatus::Payer, $client->profile(), false);

    expect($decision->reverseCharge)->toBeFalse()
        ->and($decision->mode)->toBeNull();
});

it('does not treat a CZ client as domestic for SK reverse charge', function (): void {
    $client = Client::factory()->make(['country' => 'CZ', 'vat_id' => null, 'reverse_charge_allowed' => true]);

    $this->resolver->resolve(VatStatus::Payer, $client->profile(), true);
})->throws(DomainException::class);
