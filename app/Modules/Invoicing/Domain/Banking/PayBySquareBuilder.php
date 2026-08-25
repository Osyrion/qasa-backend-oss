<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Banking;

use App\Modules\Invoicing\Domain\Banking\Contracts\PaymentQrScheme;
use App\Modules\Invoicing\Domain\ValueObjects\BankAccountIdentity;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pay by Square (bysquare.com) QR payload — the primary SK QR payment
 * standard. Selected for any SK IBAN, regardless of currency (the CC field
 * in the data model carries whatever currency the document is in).
 *
 * Verified against the independent `bysquare` npm package (v4.0.0, spec-
 * referenced, "compatible with Slovak banking apps") as a reference
 * implementation — see docs/plans/PAY_BY_SQUARE_VERIFICATION_PLAN.md Fáza 1
 * and tests/Unit/Invoicing/PayBySquareGoldenTest.php. Binary layout:
 * `[2-byte Bysquare Header][2-byte Payload Length LE][LZMA1 body, 13-byte
 * "alone" header stripped]`, then base32hex-encoded without padding. The
 * uncompressed body is `[4-byte CRC32 LE][tab-separated data model]`.
 *
 * The compressed LZMA1 body is NOT expected to be byte-identical to the
 * reference encoder's own output: LZMA compression is not canonical, so two
 * different encoders (this class uses the `xz` CLI; the reference uses a
 * pure-JS encoder) can produce different, equally valid compressed bytes for
 * the same input — confirmed by decoding this class's own output with the
 * reference decoder. Only the uncompressed fields, the CRC32, and the
 * header/length prefix need to match exactly.
 *
 * Only a single bank account per payment is supported (no KS/ŠS constant or
 * specific symbol, no standing order / direct debit extensions, no
 * beneficiary address) — {@see PaymentQrRequest} has no fields for those.
 */
final class PayBySquareBuilder implements PaymentQrScheme
{
    /**
     * RFC 4648 "base32hex" alphabet — not the standard base32 alphabet.
     */
    private const string BASE32HEX_ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUV';

    /**
     * Bysquare Header, 2 bytes / 4 nibbles: [Type=0, Version=0x02 (1.2.0),
     * DocumentType=0, Reserved=0]. Version 1.2.0 is the only version this
     * builder emits.
     */
    private const string BYSQUARE_HEADER = "\x02\x00";

    /**
     * LZMA1 "alone" format dictionary size the reference implementation uses
     * (2^21 = 2 MiB) — must match what banking-app decoders expect; a
     * mismatched dictionary size still decompresses correctly locally but
     * risks rejection by a stricter embedded decoder.
     */
    private const string XZ_LZMA1_PARAMS = 'dict=2MiB,lc=3,lp=0,pb=2';

    public function name(): string
    {
        return 'paybysquare';
    }

    public function supports(BankAccountIdentity $account, Currency $currency): bool
    {
        return $account->isSkIban();
    }

    public function payload(PaymentQrRequest $request): string
    {
        return $this->build(
            iban: $request->iban,
            bic: $request->bic,
            amount: $request->amount,
            currency: $request->currency,
            variableSymbol: $request->variableSymbol,
            dueDate: $request->dueDate,
            beneficiaryName: $request->beneficiaryName ?? '',
            paymentNote: $request->message,
        );
    }

    /**
     * @throws RuntimeException when the xz binary is unavailable or fails.
     *                          PaymentQrService catches it and degrades to
     *                          "no QR" on the PDF; SupplierPaymentQrService
     *                          converts it to a DomainException, because its
     *                          QR is the whole response. Until 2026-08-21
     *                          neither did, and this threw straight through
     *                          the invoice render.
     */
    public function build(
        string $iban,
        ?string $bic,
        float $amount,
        Currency $currency,
        ?string $variableSymbol,
        ?Carbon $dueDate,
        string $beneficiaryName,
        ?string $paymentNote = null,
    ): string {
        $dataModel = $this->dataModel($iban, $bic, $amount, $currency, $variableSymbol, $dueDate, $beneficiaryName, $paymentNote);

        $withCrc = pack('V', crc32($dataModel)).$dataModel;
        $lzmaBody = substr($this->lzmaCompress($withCrc), 13);
        $payloadLength = pack('v', strlen($withCrc));

        return $this->base32HexEncode(self::BYSQUARE_HEADER.$payloadLength.$lzmaBody);
    }

    private function dataModel(
        string $iban,
        ?string $bic,
        float $amount,
        Currency $currency,
        ?string $variableSymbol,
        ?Carbon $dueDate,
        string $beneficiaryName,
        ?string $paymentNote,
    ): string {
        $fields = [
            '', // InvoiceID — not tracked
            '1', // PaymentsCount
            '1', // PaymentOptions — 1 = standard (one-off) payment
            $this->formatAmount($amount),
            $currency->value,
            $dueDate?->format('Ymd') ?? '',
            $variableSymbol !== null && $variableSymbol !== '' ? preg_replace('/\D/', '', $variableSymbol) : '',
            '', // ConstantSymbol — not tracked
            '', // SpecificSymbol — not tracked
            '', // OriginatorsReferenceInformation
            $paymentNote !== null ? Str::ascii($paymentNote) : '',
            '1', // BankAccountsCount
            strtoupper(str_replace(' ', '', $iban)),
            $bic !== null && $bic !== '' ? strtoupper($bic) : '',
            '0', // StandingOrderExt — not supported
            '0', // DirectDebitExt — not supported
            Str::ascii($beneficiaryName),
            '', // BeneficiaryAddressLine1
            '', // BeneficiaryAddressLine2
        ];

        return implode("\t", $fields);
    }

    /**
     * The spec's Amount field is a plain decimal number, not a fixed-2-decimal
     * one (spec examples include "1000" and "10.5") — the reference encoder
     * emits whatever `Number.prototype.toString()` gives, which drops a
     * trailing ".00" or trailing zero. Matched here for byte-exact parity
     * with the reference {@see tests/Unit/Invoicing/PayBySquareGoldenTest.php}.
     */
    private function formatAmount(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    }

    /**
     * Raw LZMA ("legacy .lzma alone-format": 13-byte header + compressed
     * stream) via the xz CLI — no ext-lzma in this project's PHP image, and
     * xz is present in the base Debian image without needing a Dockerfile
     * change. The caller strips the 13-byte header; only the compressed body
     * ends up in the QR payload (see {@see self::XZ_LZMA1_PARAMS}).
     *
     * @throws RuntimeException
     */
    private function lzmaCompress(string $data): string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(['xz', '--format=lzma', '--lzma1='.self::XZ_LZMA1_PARAMS, '--stdout'], $descriptors, $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the xz process for LZMA compression.');
        }

        fwrite($pipes[0], $data);
        fclose($pipes[0]);

        $compressed = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException("xz exited with code {$exitCode}.");
        }

        return $compressed;
    }

    private function base32HexEncode(string $binary): string
    {
        $bits = '';

        foreach (str_split($binary) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';

        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $output .= self::BASE32HEX_ALPHABET[(int) bindec($chunk)];
        }

        return $output;
    }
}
