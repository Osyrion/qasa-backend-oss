<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Banking\EpcQrBuilder;
use App\Modules\Invoicing\Domain\Banking\PayBySquareBuilder;
use App\Modules\Invoicing\Domain\Banking\PaymentSchemeRegistry;
use App\Modules\Invoicing\Domain\Banking\SpaydBuilder;
use App\Modules\Invoicing\Domain\ValueObjects\BankAccountIdentity;
use App\Modules\Shared\Enums\Currency;

function schemeRegistry(): PaymentSchemeRegistry
{
    return new PaymentSchemeRegistry([
        new PayBySquareBuilder,
        new SpaydBuilder,
        new EpcQrBuilder,
    ]);
}

it('picks the scheme per the account+currency decision table', function (
    BankAccountIdentity $account,
    Currency $currency,
    ?string $expected,
): void {
    $scheme = schemeRegistry()->schemeFor($account, $currency);

    expect($scheme?->name())->toBe($expected);
})->with([
    'SK IBAN + EUR -> Pay by Square' => [BankAccountIdentity::fromIban('SK3112000000198742637541'), Currency::EUR, 'paybysquare'],
    'SK IBAN + CZK -> Pay by Square' => [BankAccountIdentity::fromIban('SK3112000000198742637541'), Currency::CZK, 'paybysquare'],
    'SK IBAN + USD -> Pay by Square' => [BankAccountIdentity::fromIban('SK3112000000198742637541'), Currency::USD, 'paybysquare'],
    'CZ IBAN + CZK -> SPAYD' => [BankAccountIdentity::fromIban('CZ6508000000192000145399'), Currency::CZK, 'spayd'],
    'CZ IBAN + EUR -> SPAYD' => [BankAccountIdentity::fromIban('CZ6508000000192000145399'), Currency::EUR, 'spayd'],
    'CZ domestic account + CZK -> SPAYD' => [BankAccountIdentity::fromDomestic('19-2000145399', '0800'), Currency::CZK, 'spayd'],
    'CZ domestic account + EUR -> SPAYD' => [BankAccountIdentity::fromDomestic('19-2000145399', '0800'), Currency::EUR, 'spayd'],
    'other IBAN + EUR -> EPC' => [BankAccountIdentity::fromIban('DE89370400440532013000'), Currency::EUR, 'epc'],
    'other IBAN + CZK -> none' => [BankAccountIdentity::fromIban('DE89370400440532013000'), Currency::CZK, null],
    'other IBAN + USD -> none' => [BankAccountIdentity::fromIban('DE89370400440532013000'), Currency::USD, null],
]);

it('resolves an identity preferring an explicit IBAN over a domestic account', function (): void {
    $identity = BankAccountIdentity::resolve('SK3112000000198742637541', '123456789', '0100');

    expect($identity?->iban)->toBe('SK3112000000198742637541')
        ->and($identity?->domesticAccountNumber)->toBeNull();
});

it('resolves a domestic identity when no IBAN is given', function (): void {
    $identity = BankAccountIdentity::resolve(null, '123456789', '0100');

    expect($identity?->iban)->toBeNull()
        ->and($identity?->domesticAccountNumber)->toBe('123456789')
        ->and($identity?->isCz())->toBeTrue();
});

it('resolves to null when neither an IBAN nor a domestic account is given', function (): void {
    expect(BankAccountIdentity::resolve(null, null, null))->toBeNull();
});
