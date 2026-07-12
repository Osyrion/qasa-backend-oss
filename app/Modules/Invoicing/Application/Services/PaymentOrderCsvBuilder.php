<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Domain\Models\PaymentOrder;
use App\Modules\Invoicing\Domain\Models\PaymentOrderItem;
use League\Csv\ByteSequence;
use League\Csv\Writer;

/**
 * One row per payment — human-readable batch overview for a spreadsheet.
 * Uses ';' + UTF-8 BOM for Excel compatibility in CZ/SK locale (same as
 * InvoiceCsvBuilder). Reads exclusively the frozen snapshot rows.
 */
final class PaymentOrderCsvBuilder
{
    private const COLUMNS = [
        'vendor_name', 'supplier_invoice_number', 'account', 'iban', 'bic',
        'variable_symbol', 'constant_symbol', 'amount', 'currency', 'due_date',
    ];

    public function build(PaymentOrder $order): string
    {
        $writer = Writer::createFromString('');
        $writer->setDelimiter(';');
        $writer->setOutputBOM(ByteSequence::BOM_UTF8);

        $writer->insertOne(array_map(
            static fn (string $column): string => (string) __("invoicing.payment_order_export.csv_headers.{$column}"),
            self::COLUMNS,
        ));

        foreach ($order->items as $item) {
            $writer->insertOne($this->row($order, $item));
        }

        return $writer->toString();
    }

    /**
     * @return list<string>
     */
    private function row(PaymentOrder $order, PaymentOrderItem $item): array
    {
        return [
            $item->vendor_name,
            $item->supplier_invoice_number,
            $item->hasDomesticAccount() ? $item->account_number.'/'.$item->bank_code : '',
            $item->iban ?? '',
            $item->bic ?? '',
            $item->variable_symbol ?? '',
            $order->constant_symbol ?? '',
            number_format((float) $item->amount, 2, '.', ''),
            $order->currency->value,
            $order->due_date->format('Y-m-d'),
        ];
    }
}
