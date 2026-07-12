<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Domain\Models\Invoice;
use League\Csv\ByteSequence;
use League\Csv\Writer;

/**
 * One row per invoice (header-level export) for a general-purpose
 * spreadsheet. Uses ';' + UTF-8 BOM for Excel compatibility in CZ/SK locale.
 */
final class InvoiceCsvBuilder
{
    private const COLUMNS = [
        'invoice_number', 'type', 'status', 'issued_at', 'taxable_supply_at', 'due_at',
        'client_name', 'client_ico', 'client_dic', 'client_vat_id', 'currency',
        'subtotal', 'discount_amount', 'vat_amount', 'total', 'paid_amount', 'balance',
        'variable_symbol', 'exchange_rate', 'reverse_charge',
    ];

    /**
     * @param  iterable<Invoice>  $invoices
     */
    public function build(iterable $invoices): string
    {
        $writer = Writer::createFromString('');
        $writer->setDelimiter(';');
        $writer->setOutputBOM(ByteSequence::BOM_UTF8);

        $writer->insertOne(array_map(
            static fn (string $column): string => (string) __("invoicing.export.csv_headers.{$column}"),
            self::COLUMNS,
        ));

        foreach ($invoices as $invoice) {
            $writer->insertOne($this->row($invoice));
        }

        return $writer->toString();
    }

    /**
     * @return list<string>
     */
    private function row(Invoice $invoice): array
    {
        $client = $invoice->client_snapshot ?? $this->clientFallback($invoice);

        return [
            $invoice->invoice_number,
            $invoice->type->value,
            $invoice->status,
            $invoice->issued_at->format('Y-m-d'),
            $invoice->taxable_supply_at?->format('Y-m-d') ?? '',
            $invoice->due_at->format('Y-m-d'),
            (string) ($client['name'] ?? ''),
            (string) ($client['ico'] ?? ''),
            (string) ($client['dic'] ?? ''),
            (string) ($client['vat_id'] ?? ''),
            $invoice->currency->value,
            $this->money((float) $invoice->subtotal),
            $this->money((float) $invoice->discount_amount),
            $this->money((float) $invoice->vat_amount),
            $this->money((float) $invoice->total),
            $this->money((float) $invoice->payments->sum('amount')),
            $this->money($invoice->balance()),
            $invoice->variable_symbol ?? '',
            $invoice->exchange_rate_snapshot !== null ? $this->money((float) $invoice->exchange_rate_snapshot, 6) : '',
            $invoice->reverse_charge_mode !== null ? $invoice->reverse_charge_mode->value : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function clientFallback(Invoice $invoice): array
    {
        $client = $invoice->client;

        if ($client === null) {
            return [];
        }

        return [
            'name' => $client->display_name,
            'ico' => $client->ico,
            'dic' => $client->dic,
            'vat_id' => $client->vat_id,
        ];
    }

    private function money(float $value, int $decimals = 2): string
    {
        return number_format($value, $decimals, '.', '');
    }
}
