<?php

declare(strict_types=1);

use App\Modules\Taxation\Domain\Rules\ValidCzDic;
use App\Modules\Taxation\Domain\Rules\ValidCzIco;
use App\Modules\Taxation\Domain\Rules\ValidSkDic;
use App\Modules\Taxation\Domain\Rules\ValidSkIco;
use App\Modules\Taxation\Domain\Rules\ValidSkVatId;

it('validates SK IČO by mod-11 divisibility, including the boundary', function (): void {
    expect(ValidSkIco::isValid('11000000'))->toBeTrue()
        ->and(ValidSkIco::isValid('11000001'))->toBeFalse()
        ->and(ValidSkIco::isValid('1234567'))->toBeFalse()
        ->and(ValidSkIco::isValid('123456789'))->toBeFalse()
        ->and(ValidSkIco::isValid('1234567a'))->toBeFalse();
});

it('validates CZ IČO by the weighted mod-11 check digit, including both boundary remainders', function (): void {
    // remainder 0 -> check digit 1
    expect(ValidCzIco::isValid('10000071'))->toBeTrue()
        // remainder 1 -> check digit 0
        ->and(ValidCzIco::isValid('10000020'))->toBeTrue()
        // general case, remainder 2 -> check digit 9
        ->and(ValidCzIco::isValid('12345679'))->toBeTrue()
        ->and(ValidCzIco::isValid('12345678'))->toBeFalse()
        ->and(ValidCzIco::isValid('1234567'))->toBeFalse();
});

it('validates SK DIČ as a plain 10-digit string', function (): void {
    expect(ValidSkDic::isValid('1234567890'))->toBeTrue()
        ->and(ValidSkDic::isValid('123456789'))->toBeFalse()
        ->and(ValidSkDic::isValid('SK1234567890'))->toBeFalse();
});

it('validates SK IČ DPH as SK + 10 digits', function (): void {
    expect(ValidSkVatId::isValid('SK1234567890'))->toBeTrue()
        ->and(ValidSkVatId::isValid('1234567890'))->toBeFalse()
        ->and(ValidSkVatId::isValid('CZ1234567890'))->toBeFalse();
});

it('validates CZ DIČ/IČ DPH as CZ + 8-10 digits', function (): void {
    expect(ValidCzDic::isValid('CZ12345678'))->toBeTrue()
        ->and(ValidCzDic::isValid('CZ123456789'))->toBeTrue()
        ->and(ValidCzDic::isValid('CZ1234567890'))->toBeTrue()
        ->and(ValidCzDic::isValid('CZ1234567'))->toBeFalse()
        ->and(ValidCzDic::isValid('12345678'))->toBeFalse();
});
