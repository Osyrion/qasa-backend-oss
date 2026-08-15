<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Banking\PayBySquareBuilder;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Golden vectors verified against the independent `bysquare` npm package
| (v4.0.0) — see docs/plans/PAY_BY_SQUARE_VERIFICATION_PLAN.md Fáza 1. That
| reference confirmed each fixture below decodes back to the exact input
| fields, cross-checking IBAN/BIC/amount/VS/due date/beneficiary name/note.
|
| The LZMA-compressed body is NOT asserted byte-for-byte against the
| reference: LZMA compression is not canonical (different encoders can
| produce different, equally valid compressed bytes for the same input —
| confirmed empirically, the `xz` CLI and the reference's pure-JS encoder
| diverge on anything past a trivially short payload). Instead this test
| decompresses this builder's own output and asserts the *decompressed*
| bytes — CRC32 and tab-separated data model — match byte-for-byte what the
| reference produced, alongside the deterministic (uncompressed) framing:
| the 2-byte Bysquare Header and 2-byte Payload Length prefix.
|--------------------------------------------------------------------------
*/

function binFromHex(string $hex): string
{
    $result = hex2bin($hex);

    if ($result === false) {
        throw new RuntimeException('Invalid hex fixture string.');
    }

    return $result;
}

/**
 * @return array{header: string, payloadLength: int, lzmaBody: string}
 */
function decodePayBySquareFrame(string $base32hex): array
{
    $bits = '';

    foreach (str_split($base32hex) as $char) {
        $index = strpos('0123456789ABCDEFGHIJKLMNOPQRSTUV', $char);
        expect($index)->not->toBeFalse();
        $bits .= str_pad(decbin((int) $index), 5, '0', STR_PAD_LEFT);
    }

    $bytes = '';

    foreach (str_split($bits, 8) as $byteBits) {
        if (strlen($byteBits) < 8) {
            break; // Trailing padding bits from base32hex's 5-bit grouping.
        }
        // Eight bits can only be 0..255, but PHP 8.5 deprecated chr() outside
        // that interval and PHPStan cannot infer the range from bindec().
        $byte = (int) bindec($byteBits);
        assert($byte >= 0 && $byte <= 255);
        $bytes .= chr($byte);
    }

    $header = substr($bytes, 0, 2);
    $unpacked = unpack('v', substr($bytes, 2, 2));

    if ($unpacked === false) {
        throw new RuntimeException('Failed to unpack the payload length.');
    }

    $payloadLength = $unpacked[1];
    $lzmaBody = substr($bytes, 4);

    return ['header' => $header, 'payloadLength' => $payloadLength, 'lzmaBody' => $lzmaBody];
}

/**
 * Rebuilds the standard 13-byte LZMA1 "alone" header this builder's `xz`
 * invocation produces (properties=0x5D for lc=3,lp=0,pb=2; dict=2MiB;
 * unknown uncompressed size, since `xz --stdout` never fills that field in)
 * and decompresses via the `xz` CLI — the same tool the builder uses to
 * compress, so this proves the body is valid, standards-conformant LZMA1,
 * not merely that our own encoder can read its own output.
 */
function decompressPayBySquareLzmaBody(string $lzmaBody): string
{
    $lzmaAloneHeader = "\x5d".pack('V', 0x00200000)."\xff\xff\xff\xff\xff\xff\xff\xff";

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(['xz', '-d', '--format=lzma', '--stdout'], $descriptors, $pipes);
    expect(is_resource($process))->toBeTrue();

    fwrite($pipes[0], $lzmaAloneHeader.$lzmaBody);
    fclose($pipes[0]);
    $decompressed = stream_get_contents($pipes[1]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    /** @var resource $process */
    $exitCode = proc_close($process);

    expect($exitCode)->toBe(0);

    return $decompressed;
}

/**
 * @param  array<string, mixed>  $input
 */
function assertPayBySquareGolden(array $input, string $expectedCrc32Hex, string $expectedChecked, string $expectedTabbed): void
{
    $payload = new PayBySquareBuilder()->build(
        iban: $input['iban'],
        bic: $input['bic'],
        amount: $input['amount'],
        currency: Currency::EUR,
        variableSymbol: $input['variableSymbol'],
        dueDate: $input['dueDate'],
        beneficiaryName: $input['beneficiaryName'],
        paymentNote: $input['paymentNote'],
    );

    expect($payload)->toMatch('/^[0-9A-V]+$/');

    $frame = decodePayBySquareFrame($payload);

    expect(bin2hex($frame['header']))->toBe('0200')
        ->and($frame['payloadLength'])->toBe(strlen($expectedChecked));

    $decompressed = decompressPayBySquareLzmaBody($frame['lzmaBody']);

    expect(bin2hex($decompressed))->toBe(bin2hex($expectedChecked));

    $unpackedCrc = unpack('V', substr($decompressed, 0, 4));

    if ($unpackedCrc === false) {
        throw new RuntimeException('Failed to unpack the CRC32.');
    }

    $crc = $unpackedCrc[1];
    $tabbed = substr($decompressed, 4);

    expect(strtolower(dechex($crc)))->toBe($expectedCrc32Hex)
        ->and($tabbed)->toBe($expectedTabbed);
}

it('matches the reference golden vector: basic invoice with IBAN+BIC, VS, due date, diacritics', function (): void {
    assertPayBySquareGolden(
        input: [
            'iban' => 'SK3112000000198742637541',
            'bic' => 'GIBASKBX',
            'amount' => 100.50,
            'variableSymbol' => '2026001',
            'dueDate' => Carbon::parse('2026-07-21'),
            'beneficiaryName' => 'Ján Novák',
            'paymentNote' => 'FA-2026-001',
        ],
        expectedCrc32Hex: '50f3a51b',
        expectedChecked: binFromHex('1ba5f35009310931093130302e350945555209323032363037323109323032363030310909090946412d323032362d303031093109534b333131323030303030303139383734323633373534310947494241534b425809300930094a616e204e6f76616b0909'),
        expectedTabbed: "\t1\t1\t100.5\tEUR\t20260721\t2026001\t\t\t\tFA-2026-001\t1\tSK3112000000198742637541\tGIBASKBX\t0\t0\tJan Novak\t\t",
    );
});

it('matches the reference golden vector: no BIC, no due date, plain name', function (): void {
    assertPayBySquareGolden(
        input: [
            'iban' => 'SK8909000000000123456789',
            'bic' => null,
            'amount' => 45.0,
            'variableSymbol' => '123',
            'dueDate' => null,
            'beneficiaryName' => 'Peter Horváth',
            'paymentNote' => null,
        ],
        expectedCrc32Hex: '1c1aee3e',
        expectedChecked: binFromHex('3eee1a1c0931093109343509455552090931323309090909093109534b38393039303030303030303030313233343536373839090930093009506574657220486f72766174680909'),
        expectedTabbed: "\t1\t1\t45\tEUR\t\t123\t\t\t\t\t1\tSK8909000000000123456789\t\t0\t0\tPeter Horvath\t\t",
    );
});

it('matches the reference golden vector: amount 0.01', function (): void {
    assertPayBySquareGolden(
        input: [
            'iban' => 'SK2883300000002100237159',
            'bic' => 'FIOZSKBAXXX',
            'amount' => 0.01,
            'variableSymbol' => '1',
            'dueDate' => null,
            'beneficiaryName' => 'Firma s.r.o.',
            'paymentNote' => 'Preddavok',
        ],
        expectedCrc32Hex: '27ae81b0',
        expectedChecked: binFromHex('b081ae270931093109302e30310945555209093109090909507265646461766f6b093109534b323838333330303030303030323130303233373135390946494f5a534b424158585809300930094669726d6120732e722e6f2e0909'),
        expectedTabbed: "\t1\t1\t0.01\tEUR\t\t1\t\t\t\tPreddavok\t1\tSK2883300000002100237159\tFIOZSKBAXXX\t0\t0\tFirma s.r.o.\t\t",
    );
});

it('matches the reference golden vector: amount over 100 000 with a long diacritic-heavy note', function (): void {
    assertPayBySquareGolden(
        input: [
            'iban' => 'SK6807200002891987426353',
            'bic' => 'SUBASKBX',
            'amount' => 123456.78,
            'variableSymbol' => '999999',
            'dueDate' => Carbon::parse('2026-12-31'),
            'beneficiaryName' => 'Veľká Spoločnosť a.s.',
            'paymentNote' => 'Úhrada faktúry za dodávku tovaru a poskytnutie služieb podľa zmluvy č. 45/2026 - ďakujeme za spoluprácu',
        ],
        expectedCrc32Hex: '792ab6ff',
        expectedChecked: binFromHex('ffb62a7909310931093132333435362e37380945555209323032363132333109393939393939090909095568726164612066616b74757279207a6120646f6461766b7520746f76617275206120706f736b79746e7574696520736c757a69656220706f646c61207a6d6c75767920632e2034352f32303236202d2064616b756a656d65207a612073706f6c757072616375093109534b363830373230303030323839313938373432363335330953554241534b4258093009300956656c6b612053706f6c6f636e6f737420612e732e0909'),
        expectedTabbed: "\t1\t1\t123456.78\tEUR\t20261231\t999999\t\t\t\tUhrada faktury za dodavku tovaru a poskytnutie sluzieb podla zmluvy c. 45/2026 - dakujeme za spolupracu\t1\tSK6807200002891987426353\tSUBASKBX\t0\t0\tVelka Spolocnost a.s.\t\t",
    );
});

it('matches the reference golden vector: heavy Slovak diacritics in note and name, no BIC', function (): void {
    assertPayBySquareGolden(
        input: [
            'iban' => 'SK5602000000001234567890',
            'bic' => null,
            'amount' => 250.0,
            'variableSymbol' => '555',
            'dueDate' => Carbon::parse('2026-09-01'),
            'beneficiaryName' => 'Ľubomír Šťastný',
            'paymentNote' => 'Čučoriedkový koláč - žihľava',
        ],
        expectedCrc32Hex: '25c28cc4',
        expectedChecked: binFromHex('c48cc22509310931093235300945555209323032363039303109353535090909094375636f726965646b6f7679206b6f6c6163202d207a69686c617661093109534b353630323030303030303030313233343536373839300909300930094c75626f6d69722053746173746e790909'),
        expectedTabbed: "\t1\t1\t250\tEUR\t20260901\t555\t\t\t\tCucoriedkovy kolac - zihlava\t1\tSK5602000000001234567890\t\t0\t0\tLubomir Stastny\t\t",
    );
});
