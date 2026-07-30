<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Banking;

use App\Modules\Invoicing\Domain\Banking\Contracts\PaymentQrScheme;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Pay by Square (bysquare.com) QR payload — the primary SK QR payment
 * standard. Selected for any SK IBAN, regardless of currency (the CC field
 * in the data model carries whatever currency the document is in).
 *
 * ⚠️ UNVERIFIED against the official bysquare binary specification — this
 * project could not fetch the spec's reference test vectors to validate
 * against (same situation as config/omega.php's KROS export). The
 * tab-separated "data model" field order below, the CRC32-then-compress
 * order, and the base32hex encoding are this project's best reconstruction
 * from public bysquare implementations; MUST be validated against a real
 * bysquare-compatible banking app (or the official spec, once reachable)
 * before this is relied on in production. The architecture (contract,
 * registry priority, byte-exact golden test) is meant to survive that
 * validation unchanged — only the exact byte layout might need correction.
 */
final class PayBySquareBuilder implements PaymentQrScheme
{
    /**
     * RFC 4648 "base32hex" alphabet — not the standard base32 alphabet.
     */
    private const string BASE32HEX_ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUV';

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
     * @throws RuntimeException when the xz binary is unavailable — callers
     *                          (PaymentQrService) already catch broadly and
     *                          degrade to "no QR", matching every other
     *                          builder's failure mode.
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
        $compressed = $this->lzmaCompress($withCrc);

        return $this->base32HexEncode($compressed);
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
            number_format($amount, 2, '.', ''),
            $currency->value,
            $dueDate?->format('Y-m-d') ?? '',
            $variableSymbol !== null && $variableSymbol !== '' ? preg_replace('/\D/', '', $variableSymbol) : '',
            '', // ConstantSymbol — not tracked
            '', // SpecificSymbol — not tracked
            '', // OriginatorsReferenceInformation
            $paymentNote ?? '',
            '1', // BankAccountsCount
            strtoupper(str_replace(' ', '', $iban)),
            $bic !== null && $bic !== '' ? strtoupper($bic) : '',
            '', // StandingOrderExt
            '', // DirectDebitExt
            $beneficiaryName,
            '', // BeneficiaryAddressLine1
            '', // BeneficiaryAddressLine2
        ];

        return implode("\t", $fields);
    }

    /**
     * Raw LZMA ("legacy .lzma alone-format": 13-byte header + compressed
     * stream) via the xz CLI — no ext-lzma in this project's PHP image, and
     * xz is present in the base Debian image without needing a Dockerfile
     * change.
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

        $process = proc_open(['xz', '--format=lzma', '-9', '--stdout'], $descriptors, $pipes);

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
