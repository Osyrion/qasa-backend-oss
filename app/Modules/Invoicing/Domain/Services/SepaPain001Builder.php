<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Services;

use App\Modules\Invoicing\Domain\Models\PaymentOrder;
use App\Modules\Invoicing\Domain\Models\PaymentOrderItem;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Exceptions\DomainException;
use DOMDocument;
use DOMElement;
use Illuminate\Database\Eloquent\Collection;

/**
 * SEPA Credit Transfer batch file (pain.001.001.03 — ISO 20022), the widest
 * accepted version across SK/CZ internetbanking imports. EUR-only, one
 * PmtInf per batch, one CdtTrfTxInf per row.
 */
final class SepaPain001Builder
{
    private const NS = 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.03';

    public function __construct(
        private readonly CzechIbanConverter $ibanConverter,
    ) {}

    /**
     * @throws DomainException
     */
    public function build(PaymentOrder $order): string
    {
        $this->assertApplicable($order);

        $payer = $order->payer_snapshot;
        $items = $order->items;
        $total = (float) $items->sum(fn (PaymentOrderItem $item): float => (float) $item->amount);
        $msgId = substr(str_replace('-', '', (string) $order->id), 0, 35);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $document = $dom->createElementNS(self::NS, 'Document');
        $root = $dom->createElementNS(self::NS, 'CstmrCdtTrfInitn');

        $root->appendChild($this->buildGroupHeader($dom, $msgId, $items->count(), $total, (string) ($payer['label'] ?? '')));
        $root->appendChild($this->buildPaymentInfo($dom, $order, $msgId, $items, $total));

        $document->appendChild($root);
        $dom->appendChild($document);

        $xml = $dom->saveXML();

        return $xml !== false ? $xml : '';
    }

    /**
     * @throws DomainException
     */
    private function assertApplicable(PaymentOrder $order): void
    {
        if ($order->currency !== Currency::EUR) {
            throw DomainException::because(__('invoicing.payment_order_sepa_eur_only'));
        }

        if (empty($order->payer_snapshot['iban'])) {
            throw DomainException::because(__('invoicing.payment_order_sepa_payer_iban_missing'));
        }

        foreach ($order->items as $item) {
            if ($this->resolveItemIban($item) === null) {
                throw DomainException::because(__('invoicing.payment_order_sepa_item_iban_missing', [
                    'vendor' => $item->vendor_name,
                ]));
            }
        }
    }

    private function resolveItemIban(PaymentOrderItem $item): ?string
    {
        if ($item->iban !== null && $item->iban !== '') {
            return $item->iban;
        }

        if ($item->hasDomesticAccount()) {
            return $this->ibanConverter->toIban((string) $item->account_number, (string) $item->bank_code);
        }

        return null;
    }

    private function buildGroupHeader(DOMDocument $dom, string $msgId, int $txCount, float $total, string $payerName): DOMElement
    {
        $grpHdr = $dom->createElementNS(self::NS, 'GrpHdr');

        $grpHdr->appendChild($this->el($dom, 'MsgId', $msgId));
        $grpHdr->appendChild($this->el($dom, 'CreDtTm', now()->toIso8601String()));
        $grpHdr->appendChild($this->el($dom, 'NbOfTxs', (string) $txCount));
        $grpHdr->appendChild($this->el($dom, 'CtrlSum', $this->number($total)));

        $initgPty = $dom->createElementNS(self::NS, 'InitgPty');
        $initgPty->appendChild($this->el($dom, 'Nm', $payerName));
        $grpHdr->appendChild($initgPty);

        return $grpHdr;
    }

    /**
     * @param  Collection<int, PaymentOrderItem>  $items
     */
    private function buildPaymentInfo(DOMDocument $dom, PaymentOrder $order, string $msgId, $items, float $total): DOMElement
    {
        $payer = $order->payer_snapshot;

        $pmtInf = $dom->createElementNS(self::NS, 'PmtInf');
        $pmtInf->appendChild($this->el($dom, 'PmtInfId', $msgId));
        $pmtInf->appendChild($this->el($dom, 'PmtMtd', 'TRF'));
        $pmtInf->appendChild($this->el($dom, 'NbOfTxs', (string) $items->count()));
        $pmtInf->appendChild($this->el($dom, 'CtrlSum', $this->number($total)));

        $pmtTpInf = $dom->createElementNS(self::NS, 'PmtTpInf');
        $svcLvl = $dom->createElementNS(self::NS, 'SvcLvl');
        $svcLvl->appendChild($this->el($dom, 'Cd', 'SEPA'));
        $pmtTpInf->appendChild($svcLvl);
        $pmtInf->appendChild($pmtTpInf);

        $pmtInf->appendChild($this->el($dom, 'ReqdExctnDt', $order->due_date->format('Y-m-d')));

        $dbtr = $dom->createElementNS(self::NS, 'Dbtr');
        $dbtr->appendChild($this->el($dom, 'Nm', (string) ($payer['label'] ?? '')));
        $pmtInf->appendChild($dbtr);

        $dbtrAcct = $dom->createElementNS(self::NS, 'DbtrAcct');
        $dbtrAcctId = $dom->createElementNS(self::NS, 'Id');
        $dbtrAcctId->appendChild($this->el($dom, 'IBAN', $this->normalizeIban((string) $payer['iban'])));
        $dbtrAcct->appendChild($dbtrAcctId);
        $pmtInf->appendChild($dbtrAcct);

        $pmtInf->appendChild($this->buildAgent($dom, 'DbtrAgt', isset($payer['bic']) ? (string) $payer['bic'] : null));

        $pmtInf->appendChild($this->el($dom, 'ChrgBr', 'SLEV'));

        foreach ($items as $item) {
            $pmtInf->appendChild($this->buildTransaction($dom, $order, $item));
        }

        return $pmtInf;
    }

    private function buildTransaction(DOMDocument $dom, PaymentOrder $order, PaymentOrderItem $item): DOMElement
    {
        $tx = $dom->createElementNS(self::NS, 'CdtTrfTxInf');

        $pmtId = $dom->createElementNS(self::NS, 'PmtId');
        $pmtId->appendChild($this->el($dom, 'EndToEndId', $this->endToEndId($order, $item)));
        $tx->appendChild($pmtId);

        $amt = $dom->createElementNS(self::NS, 'Amt');
        $instdAmt = $this->el($dom, 'InstdAmt', $this->number((float) $item->amount));
        $instdAmt->setAttribute('Ccy', 'EUR');
        $amt->appendChild($instdAmt);
        $tx->appendChild($amt);

        if ($item->bic !== null && $item->bic !== '') {
            $tx->appendChild($this->buildAgent($dom, 'CdtrAgt', $item->bic));
        }

        $cdtr = $dom->createElementNS(self::NS, 'Cdtr');
        $cdtr->appendChild($this->el($dom, 'Nm', $item->vendor_name));
        $tx->appendChild($cdtr);

        $cdtrAcct = $dom->createElementNS(self::NS, 'CdtrAcct');
        $cdtrAcctId = $dom->createElementNS(self::NS, 'Id');
        $cdtrAcctId->appendChild($this->el($dom, 'IBAN', $this->normalizeIban((string) $this->resolveItemIban($item))));
        $cdtrAcct->appendChild($cdtrAcctId);
        $tx->appendChild($cdtrAcct);

        $rmtInf = $dom->createElementNS(self::NS, 'RmtInf');
        $rmtInf->appendChild($this->el($dom, 'Ustrd', $item->supplier_invoice_number));
        $tx->appendChild($rmtInf);

        return $tx;
    }

    private function endToEndId(PaymentOrder $order, PaymentOrderItem $item): string
    {
        $parts = array_filter([
            $item->variable_symbol !== null && $item->variable_symbol !== '' ? 'VS'.$item->variable_symbol : null,
            $order->constant_symbol !== null && $order->constant_symbol !== '' ? 'KS'.$order->constant_symbol : null,
        ]);

        if ($parts === []) {
            return 'NOTPROVIDED';
        }

        return substr('/'.implode('/', $parts), 0, 35);
    }

    /**
     * FinInstnId's BIC is optional in pain.001.001.03 — when it's missing,
     * an Othr/Id placeholder is used instead of leaving the agent empty
     * (IBAN-only routing, still schema-valid).
     */
    private function buildAgent(DOMDocument $dom, string $elementName, ?string $bic): DOMElement
    {
        $agent = $dom->createElementNS(self::NS, $elementName);
        $finInstnId = $dom->createElementNS(self::NS, 'FinInstnId');

        if ($bic !== null && $bic !== '') {
            $finInstnId->appendChild($this->el($dom, 'BIC', strtoupper($bic)));
        } else {
            $othr = $dom->createElementNS(self::NS, 'Othr');
            $othr->appendChild($this->el($dom, 'Id', 'NOTPROVIDED'));
            $finInstnId->appendChild($othr);
        }

        $agent->appendChild($finInstnId);

        return $agent;
    }

    private function normalizeIban(string $iban): string
    {
        return strtoupper(str_replace(' ', '', $iban));
    }

    private function number(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function el(DOMDocument $dom, string $name, string $value): DOMElement
    {
        $el = $dom->createElementNS(self::NS, $name);
        $el->appendChild($dom->createTextNode($value));

        return $el;
    }
}
