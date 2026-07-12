<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Services;

use App\Modules\Invoicing\Domain\Models\PaymentOrder;
use App\Modules\Invoicing\Domain\Models\PaymentOrderItem;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Str;

/**
 * ABO (KPC) bulk domestic payment order file — "hromadný příkaz k úhradě"
 * as consumed by Czech internetbanking imports (ČS, ČSOB, Fio, ...).
 *
 * The format is positional and ASCII-only:
 *   UHL1 header  — creation date, client name (20 chars), client number,
 *                  file interval, security code zeros
 *   record 1     — accounting file header: data type 1501 (payment order),
 *                  file sequence number, payer bank code
 *   record 2     — group: payer account, total in haléře, due date
 *   item lines   — recipient account, amount in haléře, variable symbol,
 *                  recipient bank code combined with the constant symbol
 *                  (BBBBKKKK as one number), specific symbol
 *   3+ / 5+      — end of group / end of file
 *
 * CZK-only and domestic accounts only — anything else belongs to CSV/PDF.
 */
final class AboKpcBuilder
{
    private const string EOL = "\r\n";

    /**
     * @throws DomainException
     */
    public function build(PaymentOrder $order): string
    {
        $this->assertApplicable($order);

        $payer = $order->payer_snapshot;
        [$payerAccount, $payerBankCode] = $this->splitDomesticAccount((string) ($payer['account_number'] ?? ''));

        $items = $order->items;
        $totalHalere = 0;

        foreach ($items as $item) {
            $totalHalere += $this->toHalere((float) $item->amount);
        }

        $lines = [
            $this->header($order, (string) ($payer['label'] ?? '')),
            sprintf('1 1501 001 %s', $payerBankCode),
            sprintf('2 %s %d %s', $payerAccount, $totalHalere, $order->due_date->format('dmy')),
        ];

        foreach ($items as $item) {
            $lines[] = $this->itemLine($item, $order->constant_symbol);
        }

        $lines[] = '3 +';
        $lines[] = '5 +';

        return implode(self::EOL, $lines).self::EOL;
    }

    /**
     * @throws DomainException
     */
    private function assertApplicable(PaymentOrder $order): void
    {
        $payerAccountNumber = (string) ($order->payer_snapshot['account_number'] ?? '');

        $applicable = $order->currency === Currency::CZK
            && preg_match('/^(\d{1,6}-)?\d{2,10}\/\d{4}$/', $payerAccountNumber) === 1
            && $order->items->every(fn (PaymentOrderItem $item): bool => $item->hasDomesticAccount());

        if (! $applicable) {
            throw DomainException::because(__('invoicing.payment_order_abo_not_applicable'));
        }
    }

    private function header(PaymentOrder $order, string $clientLabel): string
    {
        $name = strtoupper(Str::ascii($clientLabel));
        $name = str_pad(substr($name, 0, 20), 20);

        return sprintf(
            'UHL1%s%s%s%s%s%s',
            now()->format('dmy'),
            $name,
            '0000000000', // client number (unassigned)
            '001',        // file interval from
            '999',        // file interval to
            '000000',     // security code (unused)
        );
    }

    private function itemLine(PaymentOrderItem $item, ?string $constantSymbol): string
    {
        // Recipient bank code and constant symbol travel as one combined
        // number (BBBBKKKK), leading zeros dropped.
        $bankAndKs = (string) (int) (($item->bank_code ?? '0000').str_pad($constantSymbol ?? '0', 4, '0', STR_PAD_LEFT));

        return sprintf(
            '%s %d %s %s %s',
            $item->account_number,
            $this->toHalere((float) $item->amount),
            $item->variable_symbol !== null && $item->variable_symbol !== '' ? ltrim($item->variable_symbol, '0') : '0',
            $bankAndKs,
            '0', // specific symbol (unused)
        );
    }

    private function toHalere(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /**
     * @return array{0: string, 1: string} [account with optional prefix, bank code]
     */
    private function splitDomesticAccount(string $accountNumber): array
    {
        [$account, $bankCode] = explode('/', $accountNumber, 2);

        return [$account, $bankCode];
    }
}
