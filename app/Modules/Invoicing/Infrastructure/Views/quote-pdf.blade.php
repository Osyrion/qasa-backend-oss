@php
    /** @var \App\Modules\Invoicing\Application\DTOs\QuotePdfViewModel $vm */
    $quote    = $vm->quote;
    $currency = $quote->currency;
    $fmt      = static fn ($v): string => number_format((float) $v, 2, ',', ' ');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #1a1a1a; line-height: 1.5; }
        .page { padding: 24px 32px; }
        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; }
        .right { text-align: right; }

        .header { margin-bottom: 24px; }
        .logo { max-width: 140px; max-height: 60px; }
        .sender-name { font-size: 15px; font-weight: bold; }
        .sender-contact { color: #555; font-size: 9px; }
        .doc-title { font-size: 18px; font-weight: bold; color: #111; text-align: right; }
        .not-tax-doc { font-size: 9px; font-weight: bold; color: #991b1b; text-align: right; }

        .parties { margin-bottom: 20px; }
        .party { border: 1px solid #e5e7eb; padding: 12px 14px; }
        .party-label { font-size: 8px; font-weight: bold; text-transform: uppercase; color: #6b7280; letter-spacing: 0.05em; margin-bottom: 5px; }
        .party-name { font-size: 12px; font-weight: bold; margin-bottom: 3px; }
        .party p { color: #555; font-size: 9.5px; line-height: 1.55; }
        .party-spacer { width: 16px; }

        .meta { margin-bottom: 20px; }
        .meta-label { font-size: 8px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; }
        .meta-value { font-size: 10.5px; font-weight: bold; }
        .meta td { padding: 2px 18px 2px 0; }

        .items { margin-bottom: 16px; }
        .items thead tr { background: #f9fafb; }
        .items th { padding: 7px 8px; text-align: left; font-size: 8px; font-weight: bold; text-transform: uppercase; color: #6b7280; letter-spacing: 0.05em; border-bottom: 2px solid #e5e7eb; }
        .items th.right { text-align: right; }
        .items td { padding: 7px 8px; border-bottom: 1px solid #f3f4f6; font-size: 9.5px; }

        .summary td { font-size: 10px; }
        .recap { width: 100%; }
        .recap th { padding: 4px 8px; font-size: 8px; text-transform: uppercase; color: #6b7280; text-align: right; border-bottom: 1px solid #e5e7eb; }
        .recap th:first-child { text-align: left; }
        .recap td { padding: 3px 8px; font-size: 9px; text-align: right; }
        .recap td:first-child { text-align: left; }
        .recap-title { font-size: 8px; font-weight: bold; text-transform: uppercase; color: #6b7280; letter-spacing: 0.05em; margin-bottom: 4px; }
        .totals td { padding: 3px 0; font-size: 10.5px; }
        .totals .grand td { border-top: 2px solid #111; padding-top: 7px; font-size: 13px; font-weight: bold; }

        .note { margin-top: 20px; padding: 10px 12px; background: #f9fafb; font-size: 9.5px; color: #555; }
        .note-label { font-size: 8px; font-weight: bold; text-transform: uppercase; color: #6b7280; margin-bottom: 3px; }
        .footer-text { margin-top: 20px; font-size: 9px; color: #555; }
        .footer { margin-top: 28px; padding-top: 12px; border-top: 1px solid #e5e7eb; font-size: 8px; color: #9ca3af; }
    </style>
</head>
<body>
<div class="page">

    <table class="header">
        <tr>
            <td style="width:55%">
                @if($vm->logoDataUri)
                    <img class="logo" src="{{ $vm->logoDataUri }}" alt="logo"><br>
                @endif
                <div class="sender-name">{{ $vm->supplier['name'] ?? '' }}</div>
                <div class="sender-contact">
                    @if(!empty($vm->supplier['email'])){{ $vm->supplier['email'] }}@endif
                    @if(!empty($vm->supplier['phone'])) | {{ $vm->supplier['phone'] }}@endif
                    @if(!empty($vm->supplier['website'])) | {{ $vm->supplier['website'] }}@endif
                </div>
            </td>
            <td style="width:45%">
                <div class="doc-title">{{ $vm->documentTitle }}</div>
                <div class="not-tax-doc">{{ __('invoices::pdf.not_tax_document') }}</div>
            </td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            <td class="party" style="width:49%">
                <div class="party-label">{{ __('invoices::pdf.supplier') }}</div>
                <div class="party-name">{{ $vm->supplier['name'] ?? '' }}</div>
                <p>
                    @if(!empty($vm->supplier['address'])){{ $vm->supplier['address'] }}<br>@endif
                    @if(!empty($vm->supplier['city'])){{ $vm->supplier['postal_code'] ?? '' }} {{ $vm->supplier['city'] }}@if(!empty($vm->supplier['country'])), {{ $vm->supplier['country'] }}@endif @endif
                </p>
                <p>
                    @foreach($vm->supplierTaxLines as $label => $value)
                        {{ $label }}: {{ $value }}<br>
                    @endforeach
                </p>
            </td>
            <td class="party-spacer"></td>
            <td class="party" style="width:49%">
                <div class="party-label">{{ __('invoices::pdf.customer') }}</div>
                <div class="party-name">{{ $vm->client['name'] ?? '' }}</div>
                <p>
                    @if(!empty($vm->client['address'])){{ $vm->client['address'] }}<br>@endif
                    @if(!empty($vm->client['city'])){{ $vm->client['postal_code'] ?? '' }} {{ $vm->client['city'] }}@if(!empty($vm->client['country'])), {{ $vm->client['country'] }}@endif @endif
                </p>
                <p>
                    @foreach($vm->clientTaxLines as $label => $value)
                        {{ $label }}: {{ $value }}<br>
                    @endforeach
                </p>
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr>
            <td>
                <table>
                    <tr>
                        <td class="meta-label">{{ __('invoices::pdf.issued_at') }}</td>
                        <td class="meta-value">{{ $quote->issued_at?->format('d.m.Y') }}</td>
                    </tr>
                    @if($quote->valid_until)
                        <tr>
                            <td class="meta-label">{{ __('invoices::pdf.valid_until') }}</td>
                            <td class="meta-value">{{ $quote->valid_until->format('d.m.Y') }}</td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    @if($quote->note_above)
        <div class="note">{{ $quote->note_above }}</div>
    @endif

    <table class="items">
        <thead>
        <tr>
            <th style="width:44%">{{ __('invoices::pdf.description') }}</th>
            <th class="right" style="width:12%">{{ __('invoices::pdf.quantity') }}</th>
            <th style="width:9%">{{ __('invoices::pdf.unit') }}</th>
            <th class="right" style="width:13%">{{ __('invoices::pdf.unit_price') }}</th>
            <th class="right" style="width:8%">{{ __('invoices::pdf.vat') }} %</th>
            <th class="right" style="width:14%">{{ __('invoices::pdf.total') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach($quote->items as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td class="right">{{ $fmt($item->quantity) }}</td>
                <td>{{ $item->unit }}</td>
                <td class="right">{{ $fmt($item->unit_price) }}</td>
                <td class="right">{{ (float) $item->vat_rate > 0 ? number_format((float) $item->vat_rate, 0).'%' : '—' }}</td>
                <td class="right">{{ $fmt($item->total_incl_vat) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="summary">
        <tr>
            <td style="width:52%">
                @if($vm->vatRecap !== [])
                    <div class="recap-title">{{ __('invoices::pdf.vat_recap') }}</div>
                    <table class="recap">
                        <tr>
                            <th>{{ __('invoices::pdf.vat_rate') }}</th>
                            <th>{{ __('invoices::pdf.vat_base') }}</th>
                            <th>{{ __('invoices::pdf.vat') }}</th>
                            <th>{{ __('invoices::pdf.total') }}</th>
                        </tr>
                        @foreach($vm->vatRecap as $row)
                            <tr>
                                <td>{{ number_format($row->rate, 0) }}%</td>
                                <td>{{ $fmt($row->base) }}</td>
                                <td>{{ $fmt($row->vat) }}</td>
                                <td>{{ $fmt($row->total) }} {{ $currency->symbol() }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif
            </td>
            <td style="width:8%"></td>
            <td style="width:40%">
                <table class="totals">
                    <tr>
                        <td>{{ __('invoices::pdf.subtotal') }}</td>
                        <td class="right">{{ $fmt($quote->subtotal) }} {{ $currency->symbol() }}</td>
                    </tr>
                    @if((float) $quote->discount_amount > 0)
                        <tr>
                            <td>{{ __('invoices::pdf.discount') }} @if($quote->discount_percent)({{ number_format((float) $quote->discount_percent, 0) }}%)@endif</td>
                            <td class="right">-{{ $fmt($quote->discount_amount) }} {{ $currency->symbol() }}</td>
                        </tr>
                    @endif
                    @if((float) $quote->vat_amount != 0.0)
                        <tr>
                            <td>{{ __('invoices::pdf.vat') }}</td>
                            <td class="right">{{ $fmt($quote->vat_amount) }} {{ $currency->symbol() }}</td>
                        </tr>
                    @endif
                    <tr class="grand">
                        <td>{{ __('invoices::pdf.total') }}</td>
                        <td class="right">{{ $fmt($quote->total) }} {{ $currency->symbol() }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if($quote->note)
        <div class="note">
            <div class="note-label">{{ __('invoices::pdf.note') }}</div>
            {{ $quote->note }}
        </div>
    @endif

    @if($vm->footerText)
        <div class="footer-text">{{ $vm->footerText }}</div>
    @endif

    <table class="footer">
        <tr>
            <td>{{ $quote->quote_number }}</td>
            <td class="right">{{ __('invoices::pdf.generated') }} {{ now()->format('d.m.Y') }}</td>
        </tr>
    </table>

</div>
</body>
</html>
