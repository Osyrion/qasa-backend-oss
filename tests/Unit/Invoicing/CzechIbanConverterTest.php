<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Services\CzechIbanConverter;

it('converts the canonical CNB example with an account prefix', function (): void {
    $converter = new CzechIbanConverter;

    expect($converter->toIban('19-2000145399', '0800'))->toBe('CZ6508000000192000145399');
});

it('converts a prefixless account and the result passes the mod-97 checksum', function (): void {
    $iban = (new CzechIbanConverter)->toIban('123456789', '0100');

    expect($iban)->toStartWith('CZ')->and(strlen((string) $iban))->toBe(24);

    // Independent ISO 13616 verification of the generated check digits.
    $rearranged = substr((string) $iban, 4).substr((string) $iban, 0, 4);
    $numeric = '';

    foreach (str_split($rearranged) as $char) {
        $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
    }

    $remainder = 0;

    foreach (str_split($numeric, 7) as $chunk) {
        $remainder = ((int) ($remainder.$chunk)) % 97;
    }

    expect($remainder)->toBe(1);
});

it('rejects malformed input', function (?string $account, string $bankCode): void {
    expect((new CzechIbanConverter)->toIban((string) $account, $bankCode))->toBeNull();
})->with([
    ['', '0800'],
    ['abc', '0800'],
    ['19-2000145399', '80'],
    ['12345678901', '0800'], // number longer than 10 digits
    ['1234567-89', '0800'],  // prefix longer than 6 digits
]);
